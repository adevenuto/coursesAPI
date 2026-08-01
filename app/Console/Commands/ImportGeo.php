<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportGeo extends Command
{
    /**
     * @var string
     */
    protected $signature = 'geo:import
        {--refresh : Re-download the source files even if present}
        {--chunk=1000 : Rows per bulk insert}';

    /**
     * @var string
     */
    protected $description = 'Import the dr5hn regions/subregions/countries/states/cities dataset into local tables.';

    /**
     * Source files, in FK dependency order (parents first).
     *
     * @var list<array{table:string,file:string,url:string,gz:bool}>
     */
    private array $sources = [
        ['table' => 'regions', 'file' => 'regions.csv', 'gz' => false,
            'url' => 'https://raw.githubusercontent.com/dr5hn/countries-states-cities-database/master/csv/regions.csv'],
        ['table' => 'subregions', 'file' => 'subregions.csv', 'gz' => false,
            'url' => 'https://raw.githubusercontent.com/dr5hn/countries-states-cities-database/master/csv/subregions.csv'],
        ['table' => 'countries', 'file' => 'countries.csv', 'gz' => false,
            'url' => 'https://raw.githubusercontent.com/dr5hn/countries-states-cities-database/master/csv/countries.csv'],
        ['table' => 'states', 'file' => 'states.csv', 'gz' => false,
            'url' => 'https://raw.githubusercontent.com/dr5hn/countries-states-cities-database/master/csv/states.csv'],
        ['table' => 'cities', 'file' => 'cities.csv.gz', 'gz' => true,
            'url' => 'https://github.com/dr5hn/countries-states-cities-database/releases/latest/download/csv-cities.csv.gz'],
    ];

    /** Columns cast to integer (blank -> null). */
    private array $intCols = [
        'id', 'region_id', 'subregion_id', 'country_id', 'state_id',
        'parent_id', 'population', 'gdp', 'level',
    ];

    /** Columns cast to float (blank -> null). */
    private array $floatCols = ['latitude', 'longitude', 'area_sq_km'];

    /**
     * CSV header -> DB column renames (avoid clashing with relationship names).
     */
    private array $columnAliases = [
        'region' => 'region_name',
        'subregion' => 'subregion_name',
    ];

    public function handle(): int
    {
        $dir = storage_path('app/import_data/geo');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Ensure all source files are present.
        foreach ($this->sources as $src) {
            $path = $dir.'/'.$src['file'];
            if ($this->option('refresh') || ! is_file($path)) {
                if (! $this->download($src['url'], $path)) {
                    return self::FAILURE;
                }
            }
        }

        $chunkSize = max(1, (int) $this->option('chunk'));

        DB::disableQueryLog();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Truncate all target tables up front (children first is irrelevant with FK checks off).
        foreach (array_reverse($this->sources) as $src) {
            DB::table($src['table'])->truncate();
        }

        try {
            foreach ($this->sources as $src) {
                $path = $dir.'/'.$src['file'];
                $count = $this->importFile($src['table'], $path, $src['gz'], $chunkSize);
                $this->info(str_pad($src['table'], 12)." imported: {$count}");
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->newLine();
        $this->info('Geo import complete.');

        return self::SUCCESS;
    }

    private function importFile(string $table, string $path, bool $gz, int $chunkSize): int
    {
        $stream = $gz ? 'compress.zlib://'.$path : $path;
        $handle = fopen($stream, 'r');
        if ($handle === false) {
            $this->error("Unable to open {$path}");

            return 0;
        }

        $header = fgetcsv($handle, null, ',', '"', '');
        if ($header === false) {
            fclose($handle);

            return 0;
        }
        $header = array_map('trim', $header);

        $buffer = [];
        $imported = 0;

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $record = [];
            foreach ($header as $i => $col) {
                $value = $row[$i] ?? null;
                $key = $this->columnAliases[$col] ?? $col;
                if (in_array($col, $this->intCols, true)) {
                    $record[$key] = $this->intOrNull($value);
                } elseif (in_array($col, $this->floatCols, true)) {
                    $record[$key] = $this->floatOrNull($value);
                } else {
                    $record[$key] = $this->nullIfBlank($value);
                }
            }

            if ($record['id'] === null) {
                continue;
            }

            $buffer[] = $record;

            if (count($buffer) >= $chunkSize) {
                DB::table($table)->insert($buffer);
                $imported += count($buffer);
                $buffer = [];
                $this->output->write("\r".str_pad($table, 12).' '.$imported);
            }
        }

        if ($buffer !== []) {
            DB::table($table)->insert($buffer);
            $imported += count($buffer);
        }

        fclose($handle);
        $this->output->write("\r");

        return $imported;
    }

    private function download(string $url, string $path): bool
    {
        $this->info('Downloading '.basename($path).' ...');

        try {
            $response = Http::timeout(120)->withOptions(['sink' => $path])->get($url);
            if ($response->failed()) {
                $this->error("Download failed ({$response->status()}): {$url}");

                return false;
            }
        } catch (\Throwable $e) {
            $this->error('Download error: '.$e->getMessage());

            return false;
        }

        return is_file($path) && filesize($path) > 0;
    }

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function intOrNull(?string $value): ?int
    {
        $value = $this->nullIfBlank($value);

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(?string $value): ?float
    {
        $value = $this->nullIfBlank($value);

        return $value !== null && is_numeric($value) ? (float) $value : null;
    }
}
