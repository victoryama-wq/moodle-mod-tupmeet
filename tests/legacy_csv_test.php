<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_tupmeet;

/**
 * Strict CSV, safe URL and civil-time contracts using synthetic data only.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_tupmeet\local\legacy\csv_validator::class)]
final class legacy_csv_test extends \advanced_testcase {
    /**
     * Valid baseline CSV row.
     * @return array
     */
    public static function row(): array {
        return ['session_name' => 'Synthetic course', 'session_date' => '2026-09-19', 'session_time' => '17:00',
            'part' => '1', 'drive_url' => 'https://drive.google.com/file/d/Synthetic_file-1/view?usp=sharing',
            'original_filename' => 'Synthetic recording.mp4', 'visible' => '', 'tupmeetid' => ''];
    }

    /**
     * Independent CSV fixture encoding, without formula rewriting.
     * @param array $rows Cell maps
     * @return string
     */
    public static function csv(array $rows): string {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, \mod_tupmeet\local\legacy\csv_validator::HEADERS, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_values($row), ',', '"', '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }

    /**
     * Strict field validation and defaults.
     * @param string $field Field
     * @param string $value Input
     * @param bool $valid Expected acceptance
     * @dataProvider cells
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cells')]
    public function test_cells(string $field, string $value, bool $valid): void {
        $row = self::row();
        $row[$field] = $value;
        if (!$valid) {
            $this->expectException(\moodle_exception::class);
        }
        $record = \mod_tupmeet\local\legacy\csv_validator::record(
            $row,
            (object) ['id' => 1, 'timezone' => 'America/Cancun']
        );
        $this->assertSame(1, $record['tupmeetid']);
        $this->assertSame((int) ($row['visible'] !== '0'), $record['studentvisible']);
        $this->assertSame($row['original_filename'], $record['originalfilename']);
        $this->assertSame('https://drive.google.com/file/d/' . $record['drivefileid'] . '/view', $record['exporturi']);
        $this->assertArrayNotHasKey('conferencename', $record);
    }

    /**
     * CSV cells and hostile URL cases.
     * @return array
     */
    public static function cells(): array {
        $cases = [];
        foreach (
            [
            'session_date' => [['2026-02-28', true], ['2024-02-29', true], ['2026-02-29', false],
                ['2026-13-01', false], ['2026-04-31', false], ['26-09-19', false]],
            'session_time' => [['00:00', true], ['23:59', true], ['24:00', false], ['17:60', false], ['9:00', false]],
            'part' => [['1', true], ['2', true], ['9999', true], ['0', false], ['-1', false], ['1.5', false], ['10000', false]],
            'visible' => [['', true], ['0', true], ['1', true], ['true', false], ['2', false]],
            'session_name' => [['Acentos ñ, sesión', true], ['', false], [str_repeat('ñ', 256), false], ["A\tB", false]],
            'original_filename' => [['Vídeo, parte 2.mp4', true], ['', false], [str_repeat('a', 256), false]],
            'drive_url' => [
                ['https://drive.google.com/file/d/Ab_12-3/view', true],
                ['https://drive.google.com/file/d/Ab_12-3/preview?x=1#fragment', true],
                ['https://drive.google.com/file/d/' . str_repeat('A', 200) . '/view', true],
                ['https://drive.google.com/file/d/' . str_repeat('A', 201) . '/view', false],
                ['https://drive.google.com/drive/folders/Ab_12', false],
                ['https://evil.example/file/d/Ab/view', false], ['javascript:alert(1)', false], ['data:text/plain,x', false],
                ['http://drive.google.com/file/d/Ab/view', false],
                ['https://drive.google.com.evil.example/file/d/Ab/view', false],
                ['https://drive.google.com@evil.example/file/d/Ab/view', false],
                ['https://drive.google.com:443/file/d/Ab/view', false],
                ['https://drive.google.com/file/d/A%2fB/view', false],
                ['https://drive.google.com/file/d//view', false],
            ],
            ] as $field => $values
        ) {
            foreach ($values as $i => [$value, $valid]) {
                $cases[$field . $i] = [$field, $value, $valid];
            }
        }
        return $cases;
    }

    /**
     * Unicode, BOM, quotes and formula-looking names retain their exact data.
     */
    public function test_csv_roundtrip(): void {
        $row = self::row();
        $row['session_name'] = 'Ñandú, "sesión"';
        $row['original_filename'] = '  =Synthetic.mp4  ';
        $parsed = \mod_tupmeet\local\legacy\csv_validator::parse("\xEF\xBB\xBF" . self::csv([$row]));
        $this->assertSame([$row], $parsed);
        $this->assertSame('A B C', \mod_tupmeet\local\legacy\csv_validator::name(" A  B\tC "));
    }

    /**
     * File-level failures occur before planning/import.
     * @param string $kind Failure case
     * @dataProvider invalid_files
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_files')]
    public function test_invalid_file(string $kind): void {
        $csv = self::csv([self::row()]);
        switch ($kind) {
            case 'header':
                $csv = str_replace('session_name,', 'name,', $csv);
                break;
            case 'missing':
                $csv = str_replace(',tupmeetid', '', $csv);
                break;
            case 'large':
                $csv = str_repeat('A', 5 * 1024 * 1024 + 1);
                break;
            case 'rows':
                $csv = self::csv(array_fill(0, 5001, self::row()));
                break;
            case 'encoding':
                $csv .= "\xFF";
                break;
            case 'null':
                $csv .= "\0";
                break;
            case 'empty':
                $csv = '';
                break;
            case 'columns':
                $csv .= "a,b\n";
                break;
        }
        $this->expectException(\moodle_exception::class);
        \mod_tupmeet\local\legacy\csv_validator::parse($csv);
    }

    /**
     * Bounded upload failures.
     * @return array
     */
    public static function invalid_files(): array {
        return array_map(static fn($x) => [$x], ['header', 'missing', 'large', 'rows', 'encoding', 'null', 'empty', 'columns']);
    }

    /**
     * The row boundary is inclusive, not an arbitrary smaller processing batch.
     */
    public function test_maximum_rows(): void {
        $this->assertCount(5000, \mod_tupmeet\local\legacy\csv_validator::parse(self::csv(array_fill(0, 5000, self::row()))));
    }

    /**
     * Saved zones, DST and independence from PHP defaults.
     * @param string $zone Zone
     * @param string $date Civil date
     * @param string $time Civil time
     * @param string|null $expected UTC or invalid
     * @dataProvider times
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('times')]
    public function test_timezone(string $zone, string $date, string $time, ?string $expected): void {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try {
            if ($expected === null) {
                $this->expectException(\moodle_exception::class);
            }
            $stamp = \mod_tupmeet\local\legacy\csv_validator::timestamp($date, $time, $zone);
            $this->assertSame($expected, gmdate('Y-m-d H:i', $stamp));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    /**
     * DST gaps/folds cannot silently normalize an institutional session time.
     * @return array
     */
    public static function times(): array {
        return [['America/Cancun', '2026-09-19', '17:00', '2026-09-19 22:00'],
            ['America/New_York', '2026-01-10', '17:00', '2026-01-10 22:00'],
            ['America/New_York', '2026-07-10', '17:00', '2026-07-10 21:00'],
            ['America/New_York', '2026-03-08', '02:30', null],
            ['America/New_York', '2026-11-01', '01:30', null],
            ['Invalid/Timezone', '2026-09-19', '17:00', null]];
    }
}
