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
 * Bundled translations must cover English without relying on language inheritance.
 *
 * @package    mod_tupmeet
 * @copyright  2026 Tecnologico Universitario Region Sureste
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class language_test extends \advanced_testcase {
    /**
     * Read the actual bundled catalogue, without Moodle's English fallback hiding missing keys.
     *
     * @param string $language Language code
     * @return array Strings indexed by identifier
     */
    private function catalogue(string $language): array {
        $string = [];
        require(__DIR__ . '/../lang/' . $language . '/tupmeet.php');
        return $string;
    }

    /**
     * Each bundled language provides chooser information without fallback or an invented help link.
     *
     * @dataProvider chooser_languages
     * @param string $language Language code
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('chooser_languages')]
    public function test_activity_chooser_strings(string $language): void {
        $catalogue = $this->catalogue($language);
        foreach (['modulename_help', 'modulename_summary'] as $key) {
            $this->assertArrayHasKey($key, $catalogue);
            $this->assertNotSame('', trim($catalogue[$key]));
        }
        $this->assertArrayNotHasKey('modulename_link', $catalogue);
    }

    /**
     * List the bundled languages without relying on Moodle language fallback.
     *
     * @return array Bundled languages
     */
    public static function chooser_languages(): array {
        return [['en'], ['es'], ['es_mx']];
    }

    /**
     * Both Spanish catalogues must include a nonempty translation of every English identifier.
     */
    public function test_spanish_catalogues_cover_english(): void {
        $english = $this->catalogue('en');
        foreach (['es', 'es_mx'] as $language) {
            $translated = $this->catalogue($language);
            $this->assertSame([], array_keys(array_diff_key($english, $translated)), $language . ': missing keys');
            $empty = array_filter($translated, static fn($value) => !is_string($value) || trim($value) === '');
            $this->assertSame([], array_keys($empty), $language . ': empty translations');
        }
    }

    /**
     * An installed es_mx pack without an es parent must load all plugin strings in Mexican Spanish.
     */
    public function test_mexican_spanish_without_spanish_parent(): void {
        $this->resetAfterTest();
        $langroot = make_request_directory();
        mkdir($langroot . '/es_mx');
        file_put_contents($langroot . '/es_mx/langconfig.php', "<?php\n\$string['parentlanguage'] = '';\n");
        $manager = new \core_string_manager_standard($langroot, $langroot, []);
        $this->assertSame(['es_mx'], $manager->get_language_dependencies('es_mx'));
        $expected = array_intersect_key($this->catalogue('es_mx'), $this->catalogue('en'));
        $actual = $manager->load_component_strings('mod_tupmeet', 'es_mx', true, true);
        $this->assertEquals($expected, $actual);
        $this->assertSame('Entrar a Google Meet', $actual['joinmeet']);
        $this->assertSame('Administración de TUP Meet', $actual['pluginadministration']);
    }
}
