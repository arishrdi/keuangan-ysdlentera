<?php

namespace App\Actions\Reports;

use App\Models\AcademicYear;
use App\Models\Level;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The filters a user picked for a report. Each report reads only the filters it supports.
 */
final readonly class ReportFilters
{
    /**
     * A category id of -1 means "student payments" in the income report.
     */
    public function __construct(
        public ?Level $level,
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public ?AcademicYear $academicYear = null,
        public ?int $categoryId = null,
        public ?int $classroomId = null,
        public ?int $accountId = null,
    ) {}

    /**
     * Build filters from query string values, falling back to the current month and active academic year.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input, ?Level $level): self
    {
        $from = self::date($input['dari'] ?? null) ?? CarbonImmutable::now()->startOfMonth();
        $until = self::date($input['sampai'] ?? null) ?? CarbonImmutable::now()->endOfMonth();

        return new self(
            level: $level,
            from: $from->startOfDay(),
            until: $until->lt($from) ? $from->endOfDay() : $until->endOfDay(),
            academicYear: isset($input['tahun']) && is_numeric($input['tahun'])
                ? AcademicYear::query()->find((int) $input['tahun'])
                : AcademicYear::current(),
            categoryId: ($input['kategori'] ?? null) == -1 ? -1 : self::id($input['kategori'] ?? null),
            classroomId: self::id($input['kelas'] ?? null),
            accountId: self::id($input['akun'] ?? null),
        );
    }

    public static function fromRequest(Request $request): self
    {
        return self::fromArray($request->query(), Level::active());
    }

    /**
     * The same filters as query string values, for export links.
     *
     * @return array<string, int|string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'dari' => $this->from->toDateString(),
            'sampai' => $this->until->toDateString(),
            'tahun' => $this->academicYear?->id,
            'kategori' => $this->categoryId,
            'kelas' => $this->classroomId,
            'akun' => $this->accountId,
        ], fn ($value) => $value !== null);
    }

    public function periodLabel(): string
    {
        return $this->from->translatedFormat('j F Y').' – '.$this->until->translatedFormat('j F Y');
    }

    public function levelLabel(): string
    {
        return $this->level ? 'Jenjang '.$this->level->name : 'Semua jenjang';
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value) ?: null;
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
