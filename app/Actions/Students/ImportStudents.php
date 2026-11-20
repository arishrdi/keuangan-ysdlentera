<?php

namespace App\Actions\Students;

use App\Enums\Gender;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Models\AcademicYear;
use App\Models\ActivityLog;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Email;
use OpenSpout\Common\Entity\Style\Style;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Import students and their guardians from an .xlsx file for go-live.
 *
 * Rows are checked first (preview) and only valid rows are imported. A student whose NIS already
 * exists is skipped, so the same file can be uploaded again after fixing errors.
 * Guardians are matched by phone number; new guardians get a random password.
 */
class ImportStudents
{
    public const string STATUS_NEW = 'baru';

    public const string STATUS_SKIPPED = 'dilewati';

    public const string STATUS_INVALID = 'error';

    /**
     * @var array<int, string>
     */
    public const array COLUMNS = [
        'nis', 'nisn', 'nik', 'no_kk', 'nama_siswa', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir', 'jenjang', 'kelas', 'alamat',
        'nama_wali', 'hubungan_wali', 'hp_wali', 'email_wali',
    ];

    /**
     * @var array<int, string>
     */
    public const array RELATIONSHIPS = ['Ayah', 'Ibu', 'Wali'];

    /**
     * Write an empty template with the expected columns and one example row.
     */
    public function template(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'template').'.xlsx';

        SimpleExcelWriter::create($path)
            ->setHeaderStyle((new Style)->withFontBold(true)->withBackgroundColor('E8F6EE'))
            ->addRow(array_combine(self::COLUMNS, [
                '2026001', '0123456789', '3471010101180001', '3471010101100002', 'Contoh Nama Siswa', 'L', 'Bandung', '2018-05-17', 'SD', '1A', 'Jl. Merdeka No. 1',
                'Contoh Nama Wali', 'Ayah', '081234567890', 'wali@example.com',
            ]))
            ->close();

        return $path;
    }

    /**
     * Read and check every row without saving anything.
     *
     * @return array<int, array{line: int, data: array<string, string|null>, status: string, messages: array<int, string>}>
     */
    public function preview(string $path, AcademicYear $academicYear): array
    {
        $levels = Level::query()->get()->keyBy(fn (Level $level) => Str::upper($level->code));
        $classrooms = Classroom::query()->whereBelongsTo($academicYear)->get();
        $existingNis = Student::query()->whereNotNull('nis')->pluck('nis')->flip();
        $seenNis = [];
        $seenNik = [];
        $rows = [];

        foreach (SimpleExcelReader::create($path, 'xlsx')->headersToSnakeCase()->getRows() as $index => $raw) {
            $data = $this->normalize($raw);

            if (array_filter($data) === []) {
                continue;
            }

            $messages = $this->validate($data, $levels->keys()->all(), $classrooms, $levels);
            $status = $messages === [] ? self::STATUS_NEW : self::STATUS_INVALID;

            if ($data['nis'] !== null && ($existingNis->has($data['nis']) || isset($seenNis[$data['nis']]))) {
                $status = $status === self::STATUS_NEW ? self::STATUS_SKIPPED : $status;
                $messages[] = isset($seenNis[$data['nis']]) ? 'NIS ganda di dalam file.' : 'NIS sudah terdaftar, baris dilewati.';
            }

            if ($data['nis'] !== null) {
                $seenNis[$data['nis']] = true;
            }

            if ($data['nik'] !== null && isset($seenNik[$data['nik']])) {
                $status = self::STATUS_INVALID;
                $messages[] = 'NIK ganda di dalam file.';
            }

            if ($data['nik'] !== null) {
                $seenNik[$data['nik']] = true;
            }

            $rows[] = ['line' => $index + 2, 'data' => $data, 'status' => $status, 'messages' => $messages];
        }

        return $rows;
    }

    /**
     * Save the rows marked as new in the preview.
     *
     * @param  array<int, array{line: int, data: array<string, string|null>, status: string, messages: array<int, string>}>  $rows
     * @return array{students: int, guardians_created: array<int, array{name: string, phone: string, password: string}>, guardians_linked: int}
     */
    public function import(array $rows, AcademicYear $academicYear): array
    {
        $result = ['students' => 0, 'guardians_created' => [], 'guardians_linked' => 0];

        DB::transaction(function () use ($rows, $academicYear, &$result): void {
            $levels = Level::query()->get()->keyBy(fn (Level $level) => Str::upper($level->code));

            foreach ($rows as $row) {
                if ($row['status'] !== self::STATUS_NEW) {
                    continue;
                }

                $data = $row['data'];

                if ($data['nis'] !== null && Student::query()->where('nis', $data['nis'])->exists()) {
                    continue;
                }

                $level = $levels[Str::upper((string) $data['jenjang'])];

                $student = Student::query()->create([
                    'level_id' => $level->id,
                    'nis' => $data['nis'],
                    'nisn' => $data['nisn'],
                    'nik' => $data['nik'],
                    'family_card_number' => $data['no_kk'],
                    'name' => $data['nama_siswa'],
                    'gender' => Gender::from(Str::upper((string) $data['jenis_kelamin'])),
                    'birth_place' => $data['tempat_lahir'],
                    'birth_date' => $data['tanggal_lahir'],
                    'address' => $data['alamat'],
                    'status' => StudentStatus::Active,
                ]);

                $classroom = $data['kelas'] !== null
                    ? $this->findClassroom(Classroom::query()->whereBelongsTo($academicYear)->get(), $level, $data['kelas'])
                    : null;

                if ($classroom !== null) {
                    $student->enrollIn($classroom);
                }

                $result['students']++;

                if ($data['hp_wali'] === null) {
                    continue;
                }

                $guardian = User::query()->where('phone', $data['hp_wali'])->first();

                if ($guardian === null) {
                    $password = Str::lower(Str::random(4)).random_int(1000, 9999);

                    $guardian = User::query()->create([
                        'name' => $data['nama_wali'],
                        'phone' => $data['hp_wali'],
                        'email' => $data['email_wali'],
                        'password' => $password,
                        'role' => Role::Guardian,
                    ]);

                    $result['guardians_created'][] = ['name' => $guardian->name, 'phone' => (string) $guardian->phone, 'password' => $password];
                } else {
                    $result['guardians_linked']++;
                }

                $guardian->students()->syncWithoutDetaching([$student->id => ['relationship' => $data['hubungan_wali'] ?? 'Wali']]);
            }

            ActivityLog::record('student.imported', 'Mengimpor '.$result['students'].' siswa dari file Excel', properties: [
                'students' => $result['students'],
                'guardians_created' => count($result['guardians_created']),
                'guardians_linked' => $result['guardians_linked'],
            ]);
        });

        return $result;
    }

    /**
     * Write the login details of newly created guardians so the admin can hand them out.
     *
     * @param  array<int, array{name: string, phone: string, password: string}>  $guardians
     */
    public function credentials(array $guardians): string
    {
        $path = tempnam(sys_get_temp_dir(), 'akun').'.xlsx';

        SimpleExcelWriter::create($path)
            ->setHeaderStyle((new Style)->withFontBold(true))
            ->addRows(array_map(fn (array $guardian) => [
                'Nama wali' => $guardian['name'],
                'Login (No. HP)' => $guardian['phone'],
                'Password awal' => $guardian['password'],
            ], $guardians))
            ->close();

        return $path;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string|null>
     */
    private function normalize(array $raw): array
    {
        $data = [];

        foreach (self::COLUMNS as $column) {
            $value = $raw[$column] ?? null;

            $data[$column] = match (true) {
                $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->toDateString(),
                is_float($value) && floor($value) === $value => (string) (int) $value,
                $value === null => null,
                default => trim((string) $value) === '' ? null : trim((string) $value),
            };
        }

        $data['tanggal_lahir'] = $this->parseDate($data['tanggal_lahir']);
        $data['hp_wali'] = User::normalizePhone($data['hp_wali']);
        $data['email_wali'] = $data['email_wali'] !== null ? Str::lower($data['email_wali']) : null;
        $data['hubungan_wali'] = $data['hubungan_wali'] !== null ? Str::ucfirst(Str::lower($data['hubungan_wali'])) : null;

        return $data;
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = rescue(fn () => CarbonImmutable::createFromFormat('!'.$format, $value), report: false);

            if ($date instanceof CarbonImmutable && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return 'invalid:'.$value;
    }

    /**
     * @param  array<string, string|null>  $data
     * @param  array<int, string>  $levelCodes
     * @param  \Illuminate\Database\Eloquent\Collection<int, Classroom>  $classrooms
     * @param  Collection<string, Level>  $levels
     * @return array<int, string>
     */
    private function validate(array $data, array $levelCodes, $classrooms, $levels): array
    {
        $messages = Validator::make($data, [
            'nama_siswa' => ['required', 'string', 'max:255'],
            'jenis_kelamin' => ['required', 'in:L,P,l,p'],
            'jenjang' => ['required', 'in:'.implode(',', [...$levelCodes, ...array_map('strtolower', $levelCodes)])],
            'nisn' => ['nullable', 'digits_between:5,20'],
            'nik' => ['nullable', 'digits:16'],
            'no_kk' => ['nullable', 'digits:16'],
            'hp_wali' => ['nullable', 'string', 'min:9', 'max:20'],
            'nama_wali' => ['nullable', 'string', 'max:255'],
            'hubungan_wali' => ['nullable', 'in:'.implode(',', self::RELATIONSHIPS)],
            'email_wali' => ['nullable', (new Email)->rfcCompliant()],
        ], [
            'jenis_kelamin.in' => 'Jenis kelamin harus L atau P.',
            'jenjang.in' => 'Jenjang harus salah satu dari: '.implode(', ', $levelCodes).'.',
            'hubungan_wali.in' => 'Hubungan wali harus Ayah, Ibu, atau Wali.',
        ], [
            'nama_siswa' => 'nama siswa',
            'nik' => 'NIK',
            'no_kk' => 'No. KK',
            'jenis_kelamin' => 'jenis kelamin',
            'hp_wali' => 'HP wali',
            'nama_wali' => 'nama wali',
            'email_wali' => 'email wali',
        ])->errors()->all();

        if (str_starts_with((string) $data['tanggal_lahir'], 'invalid:')) {
            $messages[] = 'Tanggal lahir harus berformat YYYY-MM-DD atau DD/MM/YYYY.';
        }

        $level = $levels->get(Str::upper((string) $data['jenjang']));

        if ($level !== null && $data['kelas'] !== null && $this->findClassroom($classrooms, $level, $data['kelas']) === null) {
            $messages[] = 'Kelas '.$data['kelas'].' tidak ada di jenjang '.$level->code.' pada tahun ajaran aktif.';
        }

        if ($data['nisn'] !== null && Student::query()->where('nisn', $data['nisn'])->exists()) {
            $messages[] = 'NISN sudah terdaftar.';
        }

        if ($data['nik'] !== null && Student::query()->where('nik', $data['nik'])->exists()) {
            $messages[] = 'NIK sudah terdaftar.';
        }

        if ($data['hp_wali'] !== null) {
            $existing = User::query()->where('phone', $data['hp_wali'])->first();

            if ($existing !== null && ! $existing->hasRole(Role::Guardian)) {
                $messages[] = 'No. HP wali sudah dipakai akun staf.';
            }

            if ($existing === null && $data['nama_wali'] === null) {
                $messages[] = 'Nama wali wajib diisi untuk wali baru.';
            }

            if ($existing === null && $data['email_wali'] !== null && User::query()->where('email', $data['email_wali'])->exists()) {
                $messages[] = 'Email wali sudah dipakai akun lain.';
            }
        }

        return $messages;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Classroom>  $classrooms
     */
    private function findClassroom($classrooms, Level $level, string $name): ?Classroom
    {
        return $classrooms->first(fn (Classroom $classroom) => $classroom->level_id === $level->id
            && Str::lower($classroom->name) === Str::lower($name));
    }
}
