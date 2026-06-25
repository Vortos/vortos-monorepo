<?php

declare(strict_types=1);

namespace Vortos\Release\Version;

final readonly class SemverVersion
{
    private const PATTERN = '/^v?(?P<major>0|[1-9]\d*)\.(?P<minor>0|[1-9]\d*)\.(?P<patch>0|[1-9]\d*)(?:-(?P<pre>[0-9A-Za-z\-.]+))?(?:\+(?P<build>[0-9A-Za-z\-.]+))?$/';

    public function __construct(
        public int $major,
        public int $minor,
        public int $patch,
        public ?string $prerelease = null,
        public ?string $buildMetadata = null,
    ) {
        if ($major < 0 || $minor < 0 || $patch < 0) {
            throw new InvalidVersionException('Version components must be non-negative.');
        }
    }

    public static function parse(string $version): self
    {
        if (preg_match(self::PATTERN, trim($version), $m) !== 1) {
            throw InvalidVersionException::fromString($version);
        }

        return new self(
            major: (int) $m['major'],
            minor: (int) $m['minor'],
            patch: (int) $m['patch'],
            prerelease: ($m['pre'] ?? '') !== '' ? $m['pre'] : null,
            buildMetadata: ($m['build'] ?? '') !== '' ? $m['build'] : null,
        );
    }

    public function withBump(BumpLevel $level): self
    {
        return match ($level) {
            BumpLevel::None => new self($this->major, $this->minor, $this->patch),
            BumpLevel::Patch => new self($this->major, $this->minor, $this->patch + 1),
            BumpLevel::Minor => new self($this->major, $this->minor + 1, 0),
            BumpLevel::Major => new self($this->major + 1, 0, 0),
        };
    }

    public function withPrerelease(?string $prerelease): self
    {
        return new self($this->major, $this->minor, $this->patch, $prerelease, $this->buildMetadata);
    }

    public function isPrerelease(): bool
    {
        return $this->prerelease !== null;
    }

    public function isStable(): bool
    {
        return $this->major > 0 && $this->prerelease === null;
    }

    public function compare(self $other): int
    {
        if (($c = $this->major <=> $other->major) !== 0) {
            return $c;
        }
        if (($c = $this->minor <=> $other->minor) !== 0) {
            return $c;
        }
        if (($c = $this->patch <=> $other->patch) !== 0) {
            return $c;
        }

        return self::comparePrerelease($this->prerelease, $other->prerelease);
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function __toString(): string
    {
        $s = sprintf('v%d.%d.%d', $this->major, $this->minor, $this->patch);
        if ($this->prerelease !== null) {
            $s .= '-' . $this->prerelease;
        }
        if ($this->buildMetadata !== null) {
            $s .= '+' . $this->buildMetadata;
        }

        return $s;
    }

    /** @return array{major: int, minor: int, patch: int, prerelease: ?string, build_metadata: ?string} */
    public function toArray(): array
    {
        return [
            'major' => $this->major,
            'minor' => $this->minor,
            'patch' => $this->patch,
            'prerelease' => $this->prerelease,
            'build_metadata' => $this->buildMetadata,
        ];
    }

    private static function comparePrerelease(?string $a, ?string $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        // no prerelease > any prerelease (1.0.0 > 1.0.0-alpha)
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        $aParts = preg_split('/[.\-]/', $a);
        $bParts = preg_split('/[.\-]/', $b);
        $len = max(\count($aParts), \count($bParts));

        for ($i = 0; $i < $len; $i++) {
            if (!isset($aParts[$i])) {
                return -1;
            }
            if (!isset($bParts[$i])) {
                return 1;
            }

            $aNum = ctype_digit($aParts[$i]);
            $bNum = ctype_digit($bParts[$i]);

            if ($aNum && $bNum) {
                if (($c = (int) $aParts[$i] <=> (int) $bParts[$i]) !== 0) {
                    return $c;
                }
            } elseif ($aNum !== $bNum) {
                return $aNum ? -1 : 1;
            } else {
                if (($c = strcmp($aParts[$i], $bParts[$i])) !== 0) {
                    return $c <=> 0;
                }
            }
        }

        return 0;
    }
}
