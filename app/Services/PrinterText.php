<?php

namespace App\Services;

use Normalizer;
use UnexpectedValueException;

class PrinterText
{
    private const EMOJI_LABEL_TARGET = 16;

    /** @var array<string, string> */
    private array $substitutions = [];

    /** @var array<string, string> */
    private static array $emojiNames = [];

    private int $longestSubstitution = 1;

    public function __construct(?string $substitutionsPath = null)
    {
        $file = $substitutionsPath ?? config_path('printer-substitutions.json');
        $substitutions = json_decode(file_get_contents($file), flags: JSON_THROW_ON_ERROR);

        if (! is_object($substitutions)) {
            throw new UnexpectedValueException('Printer substitutions must be a JSON object.');
        }

        foreach ($substitutions as $original => $replacement) {
            if ($original === '' || ! is_string($replacement) || $replacement !== $this->asciiOnly($replacement)) {
                throw new UnexpectedValueException('Printer substitutions need nonempty keys and printer-safe values.');
            }

            $this->substitutions[$original] = $replacement;
            $this->longestSubstitution = max($this->longestSubstitution, count(grapheme_str_split($original)));
        }
    }

    public function convert(string $text): string
    {
        $clusters = grapheme_str_split($text);
        $count = count($clusters);
        $printed = '';

        for ($index = 0; $index < $count;) {
            // Only match entire graphemes, including any emoji modifiers and joiners.
            for ($length = min($this->longestSubstitution, $count - $index); $length > 0; $length--) {
                $candidate = $length === 1
                    ? $clusters[$index]
                    : implode('', array_slice($clusters, $index, $length));

                if (isset($this->substitutions[$candidate])) {
                    $printed .= $this->substitutions[$candidate];
                    $index += $length;

                    continue 2;
                }
            }

            $printed .= $this->convertCluster($clusters[$index++]);
        }

        return $printed;
    }

    private function convertCluster(string $cluster): string
    {
        if ($cluster === $this->asciiOnly($cluster)) {
            return $cluster;
        }

        if (preg_match('/^\p{Latin}\p{M}*$/u', $cluster)) {
            return $this->asciiOnly(Normalizer::normalize($cluster, Normalizer::FORM_D));
        }

        if (preg_match('/[\p{Extended_Pictographic}\p{Regional_Indicator}\x{20E3}]/u', $cluster)) {
            return $this->describeEmoji($cluster);
        }

        return $this->asciiOnly($cluster);
    }

    private function describeEmoji(string $cluster): string
    {
        if (self::$emojiNames === []) {
            foreach (file(resource_path('printer/emoji-names.tsv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (! str_starts_with($line, '# ')) {
                    [$emoji, $name] = explode("\t", $line, 2);
                    self::$emojiNames[$emoji] = $this->shortenEmojiName($emoji, $name);
                }
            }
        }

        $key = str_replace("\u{FE0F}", '', $cluster);

        if (isset(self::$emojiNames[$key])) {
            return '['.self::$emojiNames[$key].']';
        }

        // For a sequence absent from the catalogue, describe its recognizable parts together.
        $parts = [];
        foreach (explode("\u{200D}", $key) as $part) {
            $part = preg_replace('/\p{Emoji_Modifier}/u', '', $part);
            if (isset(self::$emojiNames[$part])) {
                $parts[] = self::$emojiNames[$part];
            }
        }

        if ($parts !== []) {
            return '['.implode(' + ', $parts).']';
        }

        return preg_match('/[\p{Emoji_Presentation}\x{FE0F}\x{20E3}]/u', $cluster) ? '[emoji]' : '';
    }

    private function shortenEmojiName(string $emoji, string $name): string
    {
        if (str_starts_with($name, 'flag: ')) {
            $place = substr($name, strlen('flag: '));
            $label = $place.' flag';

            if ($this->fitsEmojiLabel($label)) {
                return $label;
            }

            if ($this->fitsEmojiLabel($place) || ! preg_match('/^\p{Regional_Indicator}{2}$/u', $emoji)) {
                return $place;
            }

            $code = '';
            foreach (mb_str_split($emoji, encoding: 'UTF-8') as $letter) {
                $code .= chr(ord('A') + mb_ord($letter, 'UTF-8') - 0x1F1E6);
            }

            return $code.' flag';
        }

        if (str_starts_with($name, 'keycap: ')) {
            return substr($name, strlen('keycap: '));
        }

        $name = preg_replace('/^(?:person (?=[a-z]+ing\b)|face with )/', '', $name);
        $name = strtr($name, [
            'technologist' => 'coding',
            ' facing right' => ' right',
            'hand with index finger and thumb crossed' => 'finger heart',
        ]);

        if ($this->fitsEmojiLabel($name)) {
            return $name;
        }

        if (str_starts_with($name, 'family: ')) {
            $members = array_count_values(explode(', ', substr($name, strlen('family: '))));
            $groups = [];
            foreach ($members as $member => $count) {
                $groups[] = match (true) {
                    $count === 1 => $member,
                    $member === 'man' => $count.' men',
                    $member === 'woman' => $count.' women',
                    $member === 'child' => $count.' kids',
                    default => $count.' '.$member.'s',
                };
            }

            $group = implode(', ', $groups);

            return $this->fitsEmojiLabel('family: '.$group) ? 'family: '.$group : $group;
        }

        $name = preg_replace('/^Japanese (.+) button$/', 'JP $1', $name);
        $name = strtr($name, [
            'open mailbox with lowered flag' => 'open box flag down',
            'open mailbox with raised flag' => 'open box flag up',
            'closed mailbox with lowered flag' => 'shut box flag down',
            'closed mailbox with raised flag' => 'shut box flag up',
            ' in motorized wheelchair' => ' power chair',
            ' in manual wheelchair' => ' wheelchair',
            ' button (blood type)' => ' blood',
            'magnifying glass tilted ' => 'magnifier ',
            'notebook with decorative cover' => 'decorative notebook',
        ]);

        if (! $this->fitsEmojiLabel($name)) {
            return preg_replace('/(power chair|wheelchair) right$/', '$1 >', $name);
        }

        return $name;
    }

    private function fitsEmojiLabel(string $label): bool
    {
        return strlen(str_replace(' ', '', $label)) <= self::EMOJI_LABEL_TARGET;
    }

    private function asciiOnly(string $text): string
    {
        return preg_replace('/[^\x20-\x7E\r\n]/', '', $text);
    }
}
