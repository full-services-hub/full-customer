<?php

namespace FC\Services;

use FC\Actions\ConnectAccount;
use FC\FileSystem;
use FC\User;

class Connection
{
  const AUTO_CONNECTION_KEY = 'fc/automatic-connection-reponse';

  public function __construct()
  {
    register_activation_hook(FULL_CUSTOMER_FILE, [$this, 'autoConnection']);

    add_action('admin_init', [$this, 'watchForSiteUrlChange'], 0);
    add_action('admin_init', [$this, 'autoConnection']);

    add_action('admin_notices', [$this, 'notices']);
    add_filter('plugin_row_meta', [$this, 'pluginRowMeta'], 10, 2);

    add_filter('http_request_args', [$this, 'filterRequestArgs'], PHP_INT_MAX, 2);
  }

  public function watchForSiteUrlChange(): void
  {
    $user = User::instance();

    if (!$user->isAdmin() || !$user->isConnected()) {
      return;
    }

    $current = trailingslashit(home_url());
    $stored = get_option('fc/siteurl');

    if (!$stored) {
      update_option('fc/siteurl', $current);
      $stored = $current;
    }

    if ($current === $stored) {
      return;
    }

    update_option('fc/siteurl', $current);

    $done = (new ConnectAccount())->updateSiteUrl($stored);

    update_option(
      self::AUTO_CONNECTION_KEY,
      $done['success'] ? 'url-changed-success' : 'url-changed-error'
    );
  }

  public function filterRequestArgs(array $args, $url): array
  {
    if (is_string($url) && $url && strpos($url, FULL_CUSTOMER_API_DOMAIN) !== false) {
      $args['reject_unsafe_urls'] = !FULL_CUSTOMER_DEV;
      $args['sslverify'] = !FULL_CUSTOMER_DEV;
      $args['headers']['X-Full-Signature'] = $this->getConnectionToken();
      $args['timeout'] = max(2 * MINUTE_IN_SECONDS, (isset($args['timeout']) ? $args['timeout'] : 0));
    }

    return $args;
  }

  public function pluginRowMeta(array $meta, string $plugin): array
  {
    if ($plugin === plugin_basename(FULL_CUSTOMER_FILE)) {
      $pageUrl = esc_url(admin_url('admin.php?page=full'));

      foreach ($meta as $key => $action) {
        if (strpos($action, 'open-plugin-details-modal') !== false) {
          unset($meta[$key]);
          break;
        }
      }

      $meta[] = '<a href="' . $pageUrl . '">Configurações</a>';
      $meta[] = User::instance()->isConnected() ? 'Site conectado!' : '<a href="' . $pageUrl . '">Conectar site</a>';
    }

    return $meta;
  }

  private function getConnectionToken(): string
  {
    $user = User::instance();
    $anon = fcGetAnonymousUserConnection();

    global $wp_rewrite;
    $restUrl = !empty($wp_rewrite) ? rest_url() : get_home_url(null, 'wp-json');

    $connectionEmail = is_user_logged_in() ? $user->getConnectionEmail() : null;
    if (empty($connectionEmail) && is_array($anon) && !empty($anon['connection_email'])) {
      $connectionEmail = (string) $anon['connection_email'];
    }

    $wpUserEmail = is_user_logged_in() && !empty($user->wp()->user_email) ? $user->wp()->user_email : null;
    if (empty($wpUserEmail) && is_array($anon) && !empty($anon['user_id'])) {
      $anonUser = get_userdata((int) $anon['user_id']);
      $wpUserEmail = $anonUser instanceof \WP_User ? $anonUser->user_email : null;
    }

    $params = [
      'fc_version' => FULL_CUSTOMER_VERSION,
      'fc_mode' => FULL_CUSTOMER_DEV ? 'dev' : 'prod',
      'wp_version' => get_bloginfo('version'),
      'connection_email' => $connectionEmail,
      'wp_user_email' => $wpUserEmail,
      'wp_site_url' => trailingslashit(home_url()),
      'wp_admin_url' => trailingslashit(admin_url()),
      'wp_rest_url' => trailingslashit($restUrl),
    ];

    return base64_encode(wp_json_encode(apply_filters('fc/connection/token', $params)));
  }

  public function notices(): void
  {
    $user = User::instance();

    if (!$user->isAdmin()) {
      return;
    }

    $fs = FileSystem::instance();
    $message = '
    <div class="fs-admin-notice__banner notice">
      <div class="fs-admin-notice__banner-imagem">
        <img src="' . $fs->getUrl('assets/images/plugue.png') . '" alt="Conectar" />
      </div>
      <div class="fs-admin-notice__banner-barra"></div>
      <div class="fs-admin-notice__banner-conteudo">
        <div class="fs-admin-notice__banner-textos">
          <p class="fs-admin-notice__banner-nome">FULL. Services</p>
          <p class="fs-admin-notice__banner-texto">{{text}}</p>
        </div>
        <a href="' . admin_url('admin.php?page=full') . '" class="fs-admin-notice__banner-btn">{{cta}}</a>
      </div>
    </div>';

    if (!$user->isConnected()) {
      delete_option(self::AUTO_CONNECTION_KEY);
      echo str_replace(
        ['{{cta}}', '{{text}}'],
        ['Conectar site', 'Seu usuário está desconectado. Para aproveitar todos os benefícios da FULL, conecte seu site à sua conta FULL.'],
        $message
      );
      return;
    }

    $auto = get_option(self::AUTO_CONNECTION_KEY);

    if ($auto) {
      delete_option(self::AUTO_CONNECTION_KEY);

      $replace = ['Ativar plugins PRO', 'Conectamos automaticamente seu site ao painel da FULL. Aproveite para ativar seus plugins agora mesmo!'];

      if ($auto === 'upgrade') {
        $replace = ['Ver novidades', 'Atualizamos automaticamente sua conexão com o painel da FULL. Aproveite!'];
      }

      if ($auto === 'url-changed-success') {
        $replace = ['Reativar plugins', 'Notamos que a url do seu site mudou. Atualizamos automaticamente sua conexão com o painel da FULL. Aproveite!'];
      }

      if ($auto === 'url-changed-error') {
        $replace = ['Reconectar site', 'Notamos que a url do seu site mudou, mas não conseguimos atualizar automaticamente sua conexão com o painel da FULL. Por favor, refaça a conexão manualmente.'];
      }

      echo str_replace(['{{cta}}', '{{text}}'], $replace, $message);
      return;
    }
  }

  public function autoConnection(): void
  {
    $fs = FileSystem::instance();

    $connectionEmail = null;
    $connectionMode = '';

    if ($fs->isFile('conn.json')) {
      $json = $fs->getContents('conn.json') ?: '{}';
      $data = json_decode($json, true);
      $connectionEmail = isset($data['email']) && $data['email'] ? $data['email'] : null;
      $fs->delete('conn.json');

      $connectionMode = 'file';
    }

    $legacyConnection = get_option('_full_customer-connection_email');

    if ($legacyConnection) {
      $connectionEmail = $legacyConnection;

      delete_option('_full_customer-connection_email');
      delete_option('_full_customer-enabled_services');
      delete_option('_full_customer-dashboard_url');
      delete_option('_full_customer-previous-connect-site-check');

      $connectionMode = 'upgrade';
    }

    if ($connectionEmail) {
      $done = (new ConnectAccount())->handleConnection($connectionEmail);

      if ($done['success']) {
        update_option(self::AUTO_CONNECTION_KEY, $connectionMode);
      }
    }
  }
}
