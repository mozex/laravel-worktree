<?php

declare(strict_types=1);

namespace Mozex\Worktree\Support;

use DOMDocument;
use DOMElement;
use Mozex\Worktree\Exceptions\WorktreeException;

/**
 * Reads a PHPUnit XML file and sets an <env> value inside the <php> block.
 * DOMDocument is used so commented-out defaults (the ones shipped in a stock
 * Laravel phpunit.xml) are ignored and a real entry is added instead.
 */
class PhpunitConfig
{
    public function __construct(protected DOMDocument $document) {}

    public static function fromFile(string $path): self
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;

        // Without this guard a file DOMDocument cannot parse leaves an empty
        // document, and save() would then overwrite the original with nothing.
        if (@$document->load($path) === false) {
            throw WorktreeException::unreadablePhpunitFile($path);
        }

        return new self($document);
    }

    /**
     * The value of an <env> entry, or null when the file does not set it.
     */
    public function env(string $name): ?string
    {
        foreach ($this->php()->getElementsByTagName('env') as $env) {
            if ($env->getAttribute('name') === $name) {
                return $env->getAttribute('value');
            }
        }

        return null;
    }

    public function setEnv(string $name, string $value): self
    {
        $php = $this->php();

        foreach ($php->getElementsByTagName('env') as $env) {
            if ($env->getAttribute('name') === $name) {
                $env->setAttribute('value', $value);

                return $this;
            }
        }

        $entry = $this->document->createElement('env');
        $entry->setAttribute('name', $name);
        $entry->setAttribute('value', $value);
        $php->appendChild($entry);

        return $this;
    }

    public function save(string $path): void
    {
        $this->document->save($path);
    }

    protected function php(): DOMElement
    {
        $nodes = $this->document->getElementsByTagName('php');
        $existing = $nodes->item(0);

        if ($existing instanceof DOMElement) {
            return $existing;
        }

        $php = $this->document->createElement('php');
        $this->document->documentElement?->appendChild($php);

        return $php;
    }
}
