<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Monolog\Formatter;

use Monolog\Formatter\FormatterInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Cloner\Stub;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

/**
 * Formats incoming records for console output by coloring them depending on log level.
 *
 * @author Tobias Schultze <http://tobion.de>
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final class ConsoleFormatter implements FormatterInterface
{
    public const SIMPLE_FORMAT = "%datetime% %start_tag%%level_name%%end_tag% <comment>[%channel%]</> %message%%context%%extra%\n";
    public const SIMPLE_DATE = 'H:i:s';

    private const LEVEL_COLOR_MAP = [
        Level::Debug->value => 'fg=white',
        Level::Info->value => 'fg=green',
        Level::Notice->value => 'fg=blue',
        Level::Warning->value => 'fg=cyan',
        Level::Error->value => 'fg=yellow',
        Level::Critical->value => 'fg=red',
        Level::Alert->value => 'fg=red',
        Level::Emergency->value => 'fg=white;bg=red',
    ];

    private array $options;
    private VarCloner $cloner;

    /**
     * @var resource|null
     */
    private $outputBuffer;

    private CliDumper $dumper;

    /**
     * Available options:
     *   * format: The format of the outputted log string. The following placeholders are supported: %datetime%, %start_tag%, %level_name%, %end_tag%, %channel%, %message%, %context%, %extra%;
     *   * date_format: The format of the outputted date string;
     *   * colors: If true, the log string contains ANSI code to add color;
     *   * multiline: If false, "context" and "extra" are dumped on one line.
     */
    public function __construct(array $options = [])
    {
        $this->options = array_replace([
            'format' => self::SIMPLE_FORMAT,
            'date_format' => self::SIMPLE_DATE,
            'colors' => true,
            'multiline' => false,
            'level_name_format' => '%-9s',
            'ignore_empty_context_and_extra' => true,
        ], $options);

        if (class_exists(VarCloner::class)) {
            $this->cloner = new VarCloner();
            $this->cloner->addCasters([
                '*' => $this->castObject(...),
            ]);

            $this->outputBuffer = fopen('php://memory', 'r+');
            if ($this->options['multiline']) {
                $output = $this->outputBuffer;
            } else {
                $output = $this->echoLine(...);
            }

            $this->dumper = new CliDumper($output, null, CliDumper::DUMP_LIGHT_ARRAY | CliDumper::DUMP_COMMA_SEPARATOR);
        }
    }

    public function formatBatch(array $records): mixed
    {
        foreach ($records as $key => $record) {
            $records[$key] = $this->format($record);
        }

        return $records;
    }

    public function format(LogRecord $record): mixed
    {
        $record = $this->replacePlaceHolder($record);

        if (!$this->options['ignore_empty_context_and_extra'] || $record->context) {
            $context = $record->context;
            $context = ($this->options['multiline'] ? "\n" : ' ').$this->dumpData($context);
        } else {
            $context = '';
        }

        if (!$this->options['ignore_empty_context_and_extra'] || $record->extra) {
            $extra = $record->extra;
            $extra = ($this->options['multiline'] ? "\n" : ' ').$this->dumpData($extra);
        } else {
            $extra = '';
        }

        $formatted = strtr($this->options['format'], [
            '%datetime%' => $record->datetime->format($this->options['date_format']),
            '%start_tag%' => \sprintf('<%s>', self::LEVEL_COLOR_MAP[$record->level->value]),
            '%level_name%' => \sprintf($this->options['level_name_format'], $record->level->getName()),
            '%end_tag%' => '</>',
            '%channel%' => $record->channel,
            '%message%' => $this->replacePlaceHolder($record)->message,
            '%context%' => $context,
            '%extra%' => $extra,
        ]);

        return $formatted;
    }

    /**
     * @internal
     */
    public function echoLine(string $line, int $depth, string $indentPad): void
    {
        if (-1 !== $depth) {
            fwrite($this->outputBuffer, $line);
        }
    }

    /**
     * @internal
     */
    public function castObject(mixed $v, array $a, Stub $s, bool $isNested): array
    {
        if ($this->options['multiline']) {
            return $a;
        }

        if ($isNested && !$v instanceof \DateTimeInterface) {
            $s->cut = -1;
            $a = [];
        }

        return $a;
    }

    private function replacePlaceHolder(LogRecord $record): LogRecord
    {
        $message = $record->message;

        if (!str_contains($message, '{')) {
            return $record;
        }

        $context = $record->context;

        $replacements = [];
        foreach ($context as $k => $v) {
            // Remove quotes added by the dumper around string.
            $v = trim($this->dumpData($v, false), '"');
            $v = OutputFormatter::escape($v);
            $replacements['{'.$k.'}'] = \sprintf('<comment>%s</>', $v);
        }

        return $record->with(message: strtr($message, $replacements));
    }

    private function dumpData(mixed $data, ?bool $colors = null): string
    {
        if (!isset($this->dumper)) {
            return '';
        }

        if (null === $colors) {
            $this->dumper->setColors($this->options['colors']);
        } else {
            $this->dumper->setColors($colors);
        }

        if (\is_array($data) && ($data['data'] ?? null) instanceof Data) {
            $data = $data['data'];
        } elseif (!$data instanceof Data) {
            $data = $this->cloner->cloneVar($data);
        }
        $data = $data->withRefHandles(false);
        $this->dumper->dump($data);

        $dump = stream_get_contents($this->outputBuffer, -1, 0);
        rewind($this->outputBuffer);
        ftruncate($this->outputBuffer, 0);

        return rtrim($dump);
    }
}
