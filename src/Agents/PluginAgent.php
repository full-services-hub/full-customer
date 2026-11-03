<?php



namespace FC\Agents;

use FC\Actions\PluginLicenseExtract;
use FC\Actions\PluginActivationFactory;
use FC\Actions\PluginActivationManager;
use FC\Actions\PluginReactivate;
use FC\Actions\PluginAddonActivation;
use FC\Actions\PluginDownload;
use FC\FileSystem;

class PluginAgent extends AbstractAgent
{
  protected array $pluginData;
  protected array $addons;

  public function __construct(array $pluginData, array $addons = [])
  {
    $this->pluginData = $pluginData;
    $this->addons = $addons;
  }

  public function getId(): string
  {
    return $this->pluginData['slug'];
  }

  public function getName(): string
  {
    return $this->pluginData['name'];
  }

  public function getIcon(): string
  {
    return isset($this->pluginData['image_url']) && $this->pluginData['image_url']
      ? $this->pluginData['image_url']
      : FileSystem::instance()->getUrl('assets/images/icons/energy.svg');
  }

  public function actions(): array
  {
    $actions = [];

    if (!isset($this->pluginData['activation']) || intval($this->pluginData['activation']['id']) === 0) {
      $actions[] = new PluginActivationFactory($this->pluginData);
    } else {
      $activation = $this->pluginData['activation'];

      $actions[] = new PluginActivationManager($this->pluginData);

      if ($activation['status'] === 'success') {
        $actions[] = new PluginReactivate($this->pluginData);
      }

      foreach ($this->addons as $addonData) {
        $actions[] = new PluginAddonActivation($addonData);
      }
    }

    $actions[] = new PluginDownload($this->pluginData);
    $actions[] = new PluginLicenseExtract($this->pluginData);

    return $actions;
  }
}
