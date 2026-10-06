<?php

declare(strict_types=1);

namespace FC\Services;

class BackLink
{
  public const OPTION_KEY = 'fc/backlink-enabled';
  public const STATUS_ENABLED = 'yes';
  public const STATUS_DISABLED = 'no';

  public function __construct()
  {
    add_action('wp_footer', [$this, 'insertFooterNote']);
  }

  public static function isEnabled(): bool
  {
    $value = get_option(self::OPTION_KEY, self::STATUS_ENABLED);

    if (is_bool($value)) {
      return $value;
    }

    return in_array($value, [self::STATUS_ENABLED, '1', 1, true], true);
  }

  public static function setEnabled(bool $enabled): void
  {
    update_option(self::OPTION_KEY, $enabled ? self::STATUS_ENABLED : self::STATUS_DISABLED, false);
  }

  public static function toggle(): bool
  {
    $newState = !self::isEnabled();
    self::setEnabled($newState);

    return $newState;
  }

  public function insertFooterNote(): void
  {
    if (!self::isEnabled()) {
      return;
    }

    echo '<a href="https://full.services/" style="visibility: hidden; user-select: none; pointer-events: none; display: none;">plugins premium WordPress</a>';
  }
}

