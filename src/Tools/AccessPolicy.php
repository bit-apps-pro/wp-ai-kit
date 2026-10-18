<?php

namespace BitApps\AiKit\Tools;

/**
 * What the site administrator allows a channel to do, from the plugin's settings.
 *
 * Settings keys: `access_level` (`read` | `write` | `destructive`; default `read`),
 * `enabled_tools` (list of names; absent = every tool within the level),
 * `disabled_tools` (list of names, always wins), `confirm_destructive` (bool,
 * default true). OAuth grants can only narrow this, never widen it.
 */
final class AccessPolicy
{
    private string $level;

    /** @var null|string[] */
    private ?array $enabled;

    /** @var string[] */
    private array $disabled;

    /**
     * @param null|string[] $enabled
     * @param string[]      $disabled
     */
    public function __construct(string $level = Scopes::READ, ?array $enabled = null, array $disabled = [], private bool $confirmDestructive = true)
    {
        $this->level = \in_array($level, Scopes::levels(), true) ? $level : Scopes::READ;
        $this->enabled = null === $enabled ? null : array_values(array_filter($enabled, 'is_string'));
        $this->disabled = array_values(array_filter($disabled, 'is_string'));
    }

    /** @param mixed $settings */
    public static function fromSettings($settings): self
    {
        $settings = \is_array($settings) ? $settings : [];

        return new self(
            \is_string($settings['access_level'] ?? null) ? $settings['access_level'] : Scopes::READ,
            isset($settings['enabled_tools']) && \is_array($settings['enabled_tools']) ? $settings['enabled_tools'] : null,
            isset($settings['disabled_tools']) && \is_array($settings['disabled_tools']) ? $settings['disabled_tools'] : [],
            !\array_key_exists('confirm_destructive', $settings) || (bool) $settings['confirm_destructive']
        );
    }

    public function level(): string
    {
        return $this->level;
    }

    public function confirmsDestructive(): bool
    {
        return $this->confirmDestructive;
    }

    public function allows(ToolDefinition $tool): bool
    {
        if (\in_array($tool->name(), $this->disabled, true)) {
            return false;
        }

        if (!Scopes::withinLevel($tool->accessLevel(), $this->level)) {
            return false;
        }

        return null === $this->enabled || \in_array($tool->name(), $this->enabled, true);
    }
}
