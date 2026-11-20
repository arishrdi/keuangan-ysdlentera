<?php

use App\Actions\Students\ImportStudents;
use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\Classroom;
use App\Models\Level;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * Write an .xlsx import file with the template columns.
 *
 * @param  array<int, array<string, mixed>>  $rows
 */
function importFile(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'import').'.xlsx';
    $writer = SimpleExcelWriter::create($path);

    foreach ($rows as $row) {
        $writer->addRow(array_merge(array_fill_keys(ImportStudents::COLUMNS, ''), $row));
    }

    $writer->close();

    return $path;
}

/**
 * @return array{year: AcademicYear, sd: Level, classroom: Classroom}
 */
function importSetup(): array
{
    $year = AcademicYear::factory()->active()->create();
    $sd = Level::factory()->sd()->create();

    return ['year' => $year, 'sd' => $sd, 'classroom' => Classroom::factory()->for($sd)->for($year)->create(['name' => '1A', 'grade' => 1])];
}

it('previews rows and marks invalid and already registered ones', function () {
    ['year' => $year, 'sd' => $sd] = importSetup();
    Student::factory()->for($sd)->create(['nis' => '1001']);

    $rows = app(ImportStudents::class)->preview(importFile([
        ['nis' => '2001', 'nama_siswa' => 'Siswa Baru', 'jenis_kelamin' => 'L', 'jenjang' => 'SD', 'kelas' => '1A'],
        ['nis' => '1001', 'nama_siswa' => 'Sudah Ada', 'jenis_kelamin' => 'P', 'jenjang' => 'SD'],
        ['nis' => '2002', 'nama_siswa' => '', 'jenis_kelamin' => 'X', 'jenjang' => 'SMA', 'tanggal_lahir' => '31-31-2020'],
        ['nis' => '2003', 'nama_siswa' => 'Kelas Salah', 'jenis_kelamin' => 'P', 'jenjang' => 'SD', 'kelas' => '9Z'],
    ]), $year);

    expect(array_column($rows, 'status'))->toBe(['baru', 'dilewati', 'error', 'error'])
        ->and(array_column($rows, 'line'))->toBe([2, 3, 4, 5])
        ->and($rows[2]['messages'])->toContain('Jenis kelamin harus L atau P.', 'Jenjang harus salah satu dari: SD.', 'Tanggal lahir harus berformat YYYY-MM-DD atau DD/MM/YYYY.')
        ->and($rows[3]['messages'])->toContain('Kelas 9Z tidak ada di jenjang SD pada tahun ajaran aktif.');
});

it('imports NIK and family card number and rejects a NIK used twice', function () {
    ['year' => $year] = importSetup();
    Student::factory()->create(['nik' => '3471010101180009']);

    $importer = app(ImportStudents::class);
    $rows = $importer->preview(importFile([
        ['nis' => '2001', 'nik' => '3471010101180001', 'no_kk' => '3471010101100002', 'nama_siswa' => 'Kakak', 'jenis_kelamin' => 'L', 'jenjang' => 'SD'],
        ['nis' => '2002', 'nik' => '3471010101180001', 'nama_siswa' => 'NIK Ganda', 'jenis_kelamin' => 'P', 'jenjang' => 'SD'],
        ['nis' => '2003', 'nik' => '3471010101180009', 'nama_siswa' => 'NIK Terdaftar', 'jenis_kelamin' => 'P', 'jenjang' => 'SD'],
        ['nis' => '2004', 'nik' => '123', 'nama_siswa' => 'NIK Pendek', 'jenis_kelamin' => 'P', 'jenjang' => 'SD'],
    ]), $year);

    expect(array_column($rows, 'status'))->toBe(['baru', 'error', 'error', 'error'])
        ->and($rows[1]['messages'])->toContain('NIK ganda di dalam file.')
        ->and($rows[2]['messages'])->toContain('NIK sudah terdaftar.');

    $importer->import($rows, $year);

    expect(Student::query()->where('nis', '2001')->sole())
        ->nik->toBe('3471010101180001')
        ->family_card_number->toBe('3471010101100002');
});

it('imports valid rows, places students in classes and creates or links guardians', function () {
    ['year' => $year, 'classroom' => $classroom] = importSetup();
    $existingGuardian = User::factory()->guardian()->create(['phone' => '081200000001']);

    $importer = app(ImportStudents::class);
    $rows = $importer->preview(importFile([
        ['nis' => '2001', 'nama_siswa' => 'Ahmad', 'jenis_kelamin' => 'L', 'tanggal_lahir' => '17/05/2018', 'jenjang' => 'sd', 'kelas' => '1a', 'nama_wali' => 'Pak Budi', 'hubungan_wali' => 'ayah', 'hp_wali' => '+62 812-0000-0009'],
        ['nis' => '2002', 'nama_siswa' => 'Aisyah', 'jenis_kelamin' => 'P', 'jenjang' => 'SD', 'nama_wali' => 'Pak Budi', 'hp_wali' => '081200000009'],
        ['nis' => '2003', 'nama_siswa' => 'Fatimah', 'jenis_kelamin' => 'P', 'jenjang' => 'SD', 'hp_wali' => '081200000001', 'hubungan_wali' => 'Ibu'],
        ['nis' => '2004', 'nama_siswa' => '', 'jenis_kelamin' => 'P', 'jenjang' => 'SD'],
    ]), $year);

    $result = $importer->import($rows, $year);

    $ahmad = Student::query()->where('nis', '2001')->sole();
    $newGuardian = User::query()->where('phone', '081200000009')->sole();

    expect($result['students'])->toBe(3)
        ->and($result['guardians_created'])->toHaveCount(1)
        ->and($result['guardians_linked'])->toBe(2)
        ->and($ahmad->birth_date->toDateString())->toBe('2018-05-17')
        ->and($ahmad->currentEnrollment()->first()->classroom_id)->toBe($classroom->id)
        ->and($newGuardian->role)->toBe(Role::Guardian)
        ->and($newGuardian->students()->pluck('name')->sort()->values()->all())->toBe(['Ahmad', 'Aisyah'])
        ->and($newGuardian->students()->where('name', 'Ahmad')->sole()->pivot->relationship)->toBe('Ayah')
        ->and(Hash::check($result['guardians_created'][0]['password'], $newGuardian->password))->toBeTrue()
        ->and($existingGuardian->students()->sole()->name)->toBe('Fatimah')
        ->and(Student::query()->count())->toBe(3);
});

it('refuses a guardian phone that belongs to a staff account', function () {
    ['year' => $year] = importSetup();
    User::factory()->treasurer()->create(['phone' => '081299999999']);

    $rows = app(ImportStudents::class)->preview(importFile([
        ['nis' => '3001', 'nama_siswa' => 'Siswa', 'jenis_kelamin' => 'L', 'jenjang' => 'SD', 'nama_wali' => 'Wali', 'hp_wali' => '081299999999'],
    ]), $year);

    expect($rows[0]['status'])->toBe('error')
        ->and($rows[0]['messages'])->toContain('No. HP wali sudah dipakai akun staf.');
});

it('runs the whole flow from the import page', function () {
    importSetup();
    $this->actingAs(User::factory()->admin()->create());
    $path = importFile([['nis' => '4001', 'nama_siswa' => 'Dari Halaman', 'jenis_kelamin' => 'L', 'jenjang' => 'SD', 'nama_wali' => 'Bu Sari', 'hp_wali' => '081377777777']]);

    Livewire::test('pages::admin.student-import')
        ->set('file', UploadedFile::fake()->createWithContent('siswa.xlsx', file_get_contents($path)))
        ->call('preview')
        ->assertHasNoErrors()
        ->assertSee('Dari Halaman')
        ->assertSet('counts.baru', 1)
        ->call('import')
        ->assertSee('081377777777')
        ->call('downloadCredentials')
        ->assertFileDownloaded('akun-wali-baru.xlsx');

    expect(Student::query()->where('nis', '4001')->exists())->toBeTrue();
});

it('offers the template for download', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test('pages::admin.student-import')
        ->call('downloadTemplate')
        ->assertFileDownloaded('template-import-siswa.xlsx');
});

it('forbids the import page for other roles', function () {
    $this->actingAs(User::factory()->treasurer()->create())
        ->withSession([Level::SESSION_KEY => Level::ALL])
        ->get(route('admin.student-import'))
        ->assertForbidden();
});
