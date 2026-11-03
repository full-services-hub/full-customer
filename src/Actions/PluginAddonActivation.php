<?php



namespace FC\Actions;

use WP_REST_Request;
use WP_REST_Response;

class PluginAddonActivation extends AbstractAction
{
  private array $repoPlugin;

  public function __construct(array $repoPlugin)
  {
    $this->repoPlugin = $repoPlugin;
  }

  public function getIcon(): string
  {
    return $this->repoPlugin['image_url'];
  }

  public function getName(): string
  {
    return 'Ativar ' . $this->repoPlugin['name'];
  }

  public function getShortDescription(): string
  {
    return 'Instalar e ativar o addon ' . $this->repoPlugin['name'] . ' no WordPress.';
  }

  public function getId(): string
  {
    return 'activate-addon-' . $this->repoPlugin['slug'];
  }

  public function getPromptArgs(): array
  {
    return array_merge($this->_defaultPromptArgs(), [
      'id' => $this->getId(),
      'imageUrl' => $this->repoPlugin['image_url'],
      'name' => $this->getName(),
      'desc' => $this->getShortDescription(),
      'simpleRest' => false,
      'extraProps' => [
        'plugin' => $this->repoPlugin['plugin'],
        'pluginSlug' => $this->repoPlugin['slug'],
        'isAddon' => true,
        'addonName' => $this->repoPlugin['name'],
      ]
    ]);
  }

  public function isAvailable(): bool
  {
    if (!parent::isAvailable()) {
      return false;
    }

    if (!function_exists('is_plugin_active')) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    return !is_plugin_active($this->repoPlugin['plugin']);
  }

  public function showInActionsDropdown(): bool
  {
    return $this->isAvailable();
  }

  public function getRestMethod(): string
  {
    return 'POST';
  }

  public function getRestRoute(): string
  {
    return '';
  }

  public function restHandler(WP_REST_Request $request): WP_REST_Response
  {
    return new WP_REST_Response([]);
  }

  public function getCta(): string
  {
    return 'Ativar ' . $this->repoPlugin['name'];
  }
}
