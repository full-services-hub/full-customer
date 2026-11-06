<?php

namespace FC\Services;

use WP_Http_Cookie;

class LocalLicenseProcessor
{
  private array $map;

  public function __construct()
  {
    $this->map = [
      'wp-rocket' => [$this, 'wpRocket'],
      'advanced-custom-fields-pro' => [$this, 'acfPRO'],
      'all-in-one-wp-security-and-firewall-premium' => [$this, 'aiowpspf'],
      'wp-optimize-premium' => [$this, 'wpOptimize'],
      'wpforms' => [$this, 'wpforms'],
      'perfmatters' => [$this, 'perfmatters'],
      'happy-elementor-addons-pro' => [$this, 'happyElementorAddons'],
      'updraftplus' => [$this, 'updraftplus'],
      'wp-seopress-pro' => [$this, 'seoPress'],
      'ultimate-elementor' => [$this, 'ultimateAddons'],
      'astra-addon' => [$this, 'astra'],
      'seo-by-rank-math-pro' => [$this, 'rankMath'],
      'essential-addons-elementor' => [$this, 'essentialAddons'],
      'jet-theme-core' => [$this, 'jetThemeCore'],
      'elementor-pro' => [$this, 'elementorPro'],
      'full-templates' => [$this, 'fullTemplates'],
    ];
  }

  public function process(string $plugin, string $license, string $step = '', array $state = []): array
  {
    if (!isset($this->map[$plugin])) {
      return [
        'success' => false,
      ];
    }

    return call_user_func($this->map[$plugin], $license, $step, $state);
  }

  public function fullTemplates(string $license): array
  {
    return [
      'success' => true,
    ];
  }

  public function wpRocket(string $license): array
  {
    return [
      'success' => true,
    ];
  }

  private function logDev(string $message, $context = null): void
  {
    if (!defined('FULL_CUSTOMER_DEV') || !FULL_CUSTOMER_DEV) {
      return;
    }

    $logMessage = '[FC-DEV][ElementorPro] ' . $message;
    if ($context !== null) {
      $formattedContext = is_scalar($context) ? (string) $context : wp_json_encode($context);
      $logMessage .= ' | Data: ' . $formattedContext;
    }

    error_log($logMessage);
  }

  public function elementorPro(string $license, string $step = '', array $state = []): array
  {
    @set_time_limit(120);

    $this->logDev('Iniciando processamento elementorPro', [
      'step' => $step !== '' ? $step : '(initial)',
      'stateKeys' => array_keys($state),
      'hasLicense' => !empty($license),
    ]);

    if (strpos($license, ':') === false) {
      $this->logDev('Falha: formato de licença inválido (delimitador : ausente)');
      return [
        'success' => false,
        'trace' => 'invalid_license_payload',
      ];
    }

    [$licenseKey, $elementorCookiesRaw] = explode(':', $license, 2);
    $decodedCookies = base64_decode($elementorCookiesRaw, true);
    $elementorCookies = is_string($decodedCookies) ? json_decode($decodedCookies, true) : null;

    if (empty($licenseKey) || !is_array($elementorCookies)) {
      $this->logDev('Falha: licenseKey vazia ou cookies decodificados inválidos', [
        'hasLicenseKey' => !empty($licenseKey),
        'isCookiesArray' => is_array($elementorCookies),
      ]);
      return [
        'success' => false,
        'trace' => 'invalid_license_cookies',
      ];
    }

    $this->logDev('Payload de licença validado com sucesso', [
      'licenseKey' => $licenseKey,
      'cookiesCount' => count($elementorCookies),
    ]);

    if (empty($step)) {
      $this->logDev('Step 1 (inicial): Solicitando getElementorOAuthUrl');
      $oauthUrl = $this->getElementorOAuthUrl();
      if (!$oauthUrl) {
        $this->logDev('Falha: getElementorOAuthUrl retornou null');
        return [
          'success' => false,
          'trace' => 'local_authorize_internal_error',
        ];
      }

      $this->logDev('Step 1 concluído com sucesso. URL OAuth obtida', ['oauthUrl' => $oauthUrl]);

      return [
        'success' => true,
        'completed' => false,
        'step' => 'fetch_consent',
        'message' => 'Autorização local obtida. Conectando à API do Elementor...',
        'state' => [
          'oauthUrl' => $oauthUrl,
        ]
      ];
    }

    if ($step === 'fetch_consent') {
      $oauthUrl = isset($state['oauthUrl']) ? (string) $state['oauthUrl'] : '';
      $this->logDev('Step 2 (fetch_consent): Buscando consent challenge no Elementor', ['oauthUrl' => $oauthUrl]);
      return $this->fetchElementorConsentUrl($oauthUrl, $elementorCookies);
    }

    if ($step === 'submit_consent') {
      $consentUrl = isset($state['consentUrl']) ? (string) $state['consentUrl'] : '';
      $challenge = isset($state['challenge']) ? (string) $state['challenge'] : '';
      $savedCookies = isset($state['cookies']) && is_array($state['cookies']) ? $state['cookies'] : [];
      $this->logDev('Step 3 (submit_consent): Enviando consentimento para API do Elementor', [
        'consentUrl' => $consentUrl,
        'challenge' => $challenge,
        'cookiesCount' => count($savedCookies),
      ]);
      return $this->submitElementorConsent($consentUrl, $challenge, $licenseKey, $savedCookies);
    }

    if ($step === 'finalize') {
      $redirectUrl = isset($state['redirectUrl']) ? (string) $state['redirectUrl'] : '';
      $savedCookies = isset($state['cookies']) && is_array($state['cookies']) ? $state['cookies'] : [];
      $this->logDev('Step 4 (finalize): Finalizando callback OAuth e ativação local', [
        'redirectUrl' => $redirectUrl,
        'cookiesCount' => count($savedCookies),
      ]);
      return $this->finalizeElementorActivation($redirectUrl, $savedCookies);
    }

    $this->logDev('Falha: step desconhecido recebido', ['step' => $step]);

    return [
      'success' => false,
      'trace' => 'invalid_step',
    ];
  }

  private function resolveAdminUserId(): int
  {
    $currentId = get_current_user_id();
    if ($currentId > 0 && user_can($currentId, 'manage_options')) {
      return $currentId;
    }

    if (function_exists('fcGetAnonymousUserConnection')) {
      $anon = fcGetAnonymousUserConnection();
      if (is_array($anon) && !empty($anon['user_id'])) {
        $anonUserId = (int) $anon['user_id'];
        if ($anonUserId > 0 && user_can($anonUserId, 'manage_options')) {
          return $anonUserId;
        }
      }
    }

    $admins = get_users([
      'role' => 'administrator',
      'number' => 1,
      'fields' => 'ID',
      'orderby' => 'ID',
      'order' => 'ASC',
    ]);

    if (!empty($admins)) {
      return (int) $admins[0];
    }

    return 1;
  }

  private function ensureAdminUserContext(): void
  {
    $currentId = get_current_user_id();
    if ($currentId > 0 && user_can($currentId, 'manage_options')) {
      return;
    }

    $adminId = $this->resolveAdminUserId();
    if ($adminId > 0) {
      wp_set_current_user($adminId);
    }
  }

  private function resetElementorState(): void
  {
    $migrated = get_option('elementor_one_migrated_plugins', []);
    $this->logDev('Executando resetElementorState', [
      'migratedPluginsBefore' => $migrated,
      'hasLicenseKey' => (bool) get_option('elementor_pro_license_key'),
    ]);

    if (is_array($migrated) && !empty($migrated)) {
      $migrated = array_values(array_diff($migrated, ['elementor-pro']));
      update_option('elementor_one_migrated_plugins', $migrated, false);
    } else {
      delete_option('elementor_one_migrated_plugins');
    }

    delete_option('elementor_pro_license_key');
    delete_option('_elementor_pro_license_v2_data');
    delete_option('_elementor_pro_license_v2_data_fallback');
    delete_option('_elementor_pro_api_requests_lock');

    $this->logDev('resetElementorState finalizado');
  }

  private function getElementorOAuthUrl(): ?string
  {
    $this->ensureAdminUserContext();
    $this->logDev('Contexto admin garantido para getElementorOAuthUrl', [
      'currentUserId' => get_current_user_id(),
    ]);

    $this->resetElementorState();

    $this->logDev('Disparando requisição REST interna /elementor-one/v1/connect/authorize');
    $restRequest = new \WP_REST_Request('POST', '/elementor-one/v1/connect/authorize');
    $restRequest->set_param('clearSession', true);

    $response = rest_do_request($restRequest);
    if (!$response->is_error()) {
      $responseData = $response->get_data();
      $this->logDev('Resposta recebida de /elementor-one/v1/connect/authorize', [
        'is_error' => false,
        'status' => $response->get_status(),
        'hasData' => is_array($responseData) && !empty($responseData['data']),
      ]);
      if (is_array($responseData) && !empty($responseData['data']) && is_string($responseData['data'])) {
        return $responseData['data'];
      }
    } else {
      $this->logDev('Erro ao executar REST /elementor-one/v1/connect/authorize', [
        'status' => $response->get_status(),
        'data' => $response->get_data(),
      ]);
    }

    $this->logDev('Tentando fallback via Facade direto: ElementorOne\Connect\Facade');
    if (class_exists('\ElementorOne\Connect\Facade')) {
      try {
        $facade = \ElementorOne\Connect\Facade::get('elementor-one');
        if ($facade) {
          $facade->data()->clear_session(true);
          $clientId = $facade->service()->register_client();
          $this->logDev('Cliente registrado via Facade', ['clientId' => $clientId]);
          if (!empty($clientId)) {
            $authorizeUrl = $facade->utils()->get_authorize_url($clientId);
            $this->logDev('URL de autorização gerada via Facade', ['authorizeUrl' => $authorizeUrl]);
            return $authorizeUrl;
          }
        } else {
          $this->logDev('Facade::get("elementor-one") retornou null');
        }
      } catch (\Throwable $e) {
        $this->logDev('Exceção capturada no fallback do Facade', [
          'error' => $e->getMessage(),
          'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
        return null;
      }
    } else {
      $this->logDev('Classe ElementorOne\Connect\Facade não existe');
    }

    return null;
  }

  private function buildHttpCookies(array $cookiesData): array
  {
    $cookies = [];
    foreach ($cookiesData as $cookieData) {
      if (!is_array($cookieData) || empty($cookieData['name'])) {
        continue;
      }
      $cookies[] = new WP_Http_Cookie($cookieData);
    }
    return $cookies;
  }

  private function mergeResponseCookies(array $existingCookies, $response): array
  {
    $cookieMap = [];

    foreach ($existingCookies as $cookie) {
      if (!is_array($cookie) || empty($cookie['name'])) {
        continue;
      }
      $name = (string) $cookie['name'];
      $cookieMap[$name] = [
        'name' => $name,
        'value' => isset($cookie['value']) ? (string) $cookie['value'] : '',
        'path' => !empty($cookie['path']) ? (string) $cookie['path'] : '/',
        'domain' => !empty($cookie['domain']) ? (string) $cookie['domain'] : 'my.elementor.com',
      ];
    }

    $responseCookies = [];
    if (is_array($response) && isset($response['http_response']) && is_object($response['http_response'])) {
      $responseObj = $response['http_response']->get_response_object();
      if (is_object($responseObj) && isset($responseObj->cookies)) {
        $responseCookies = $responseObj->cookies;
      }
    }

    foreach ($responseCookies as $cookie) {
      $cookieArr = (array) $cookie;
      $name = isset($cookieArr['name']) ? (string) $cookieArr['name'] : '';
      if ($name === '') {
        continue;
      }

      $value = isset($cookieArr['value']) ? (string) $cookieArr['value'] : '';
      $attributes = isset($cookieArr['attributes']) ? (array) $cookieArr['attributes'] : [];

      $path = '/';
      if (!empty($attributes['path'])) {
        $path = (string) $attributes['path'];
      } elseif (!empty($cookieArr['path'])) {
        $path = (string) $cookieArr['path'];
      }

      $domain = 'my.elementor.com';
      if (!empty($attributes['domain'])) {
        $domain = (string) $attributes['domain'];
      } elseif (!empty($cookieArr['domain'])) {
        $domain = (string) $cookieArr['domain'];
      }

      $cookieMap[$name] = [
        'name' => $name,
        'value' => $value,
        'path' => $path,
        'domain' => $domain,
      ];
    }

    return array_values($cookieMap);
  }

  private function fetchElementorConsentUrl(string $oauthUrl, array $elementorCookies): array
  {
    if ($oauthUrl === '') {
      $this->logDev('Falha em fetchElementorConsentUrl: oauthUrl vazia');
      return [
        'success' => false,
        'trace' => 'consent_url_empty',
      ];
    }

    $cookies = $this->buildHttpCookies($elementorCookies);
    $this->logDev('Disparando wp_remote_get em oauthUrl', [
      'oauthUrl' => $oauthUrl,
      'cookiesCount' => count($cookies),
    ]);

    $request = wp_remote_get($oauthUrl, [
      'cookies' => $cookies,
      'timeout' => 30,
    ]);

    if (is_wp_error($request)) {
      $this->logDev('Falha no wp_remote_get de oauthUrl', ['error' => $request->get_error_message()]);
      return [
        'success' => false,
        'trace' => 'consent_url_fetch_failed',
      ];
    }

    $responseCode = wp_remote_retrieve_response_code($request);
    $consentUrl = (string) ($request['http_response']->get_response_object()->url ?? '');
    $this->logDev('Resposta recebida de oauthUrl', [
      'responseCode' => $responseCode,
      'consentUrl' => $consentUrl,
    ]);

    $parsed = wp_parse_url($consentUrl);
    $queryParams = [];
    wp_parse_str($parsed['query'] ?? '', $queryParams);
    $challenge = isset($queryParams['consent_challenge']) ? (string) $queryParams['consent_challenge'] : '';

    if ($challenge === '') {
      $this->logDev('Falha: consent_challenge não encontrado na consentUrl', ['consentUrl' => $consentUrl]);
      return [
        'success' => false,
        'trace' => 'consent_challenge_missing',
      ];
    }

    $allCookies = $this->mergeResponseCookies($elementorCookies, $request);
    $this->logDev('Consent challenge extraído com sucesso', [
      'challenge' => $challenge,
      'totalCookies' => count($allCookies),
    ]);

    return [
      'success' => true,
      'completed' => false,
      'step' => 'submit_consent',
      'message' => 'Conexão com a API do Elementor estabelecida. Autenticando...',
      'state' => [
        'consentUrl' => $consentUrl,
        'challenge' => $challenge,
        'cookies' => $allCookies,
      ]
    ];
  }

  private function submitElementorConsent(string $consentUrl, string $challenge, string $licenseKey, array $savedCookies): array
  {
    if ($consentUrl === '' || $challenge === '') {
      $this->logDev('Falha em submitElementorConsent: parâmetros ausentes', [
        'consentUrl' => $consentUrl,
        'hasChallenge' => !empty($challenge),
      ]);
      return [
        'success' => false,
        'trace' => 'consent_params_missing',
      ];
    }

    $cookies = $this->buildHttpCookies($savedCookies);
    $this->logDev('Enviando POST de consentimento para my.elementor.com/connect/api/v1/consent', [
      'challenge' => $challenge,
      'subscriptionId' => $licenseKey,
      'cookiesCount' => count($cookies),
    ]);

    $response = wp_remote_post('https://my.elementor.com/connect/api/v1/consent', [
      'cookies' => $cookies,
      'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'Referer' => $consentUrl,
      ],
      'body' => wp_json_encode([
        'consentChallenge' => $challenge,
        'subscriptionId' => $licenseKey,
        'grantScope' => ['openid', 'offline_access', 'share_usage_data'],
      ]),
      'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
      $this->logDev('Falha no POST de consentimento', ['error' => $response->get_error_message()]);
      return [
        'success' => false,
        'trace' => 'consent_submit_failed',
      ];
    }

    $responseCode = wp_remote_retrieve_response_code($response);
    $respRawBody = wp_remote_retrieve_body($response);
    $respBody = json_decode($respRawBody, true);
    $redirectUrl = is_array($respBody) && !empty($respBody['redirectUrl']) ? (string) $respBody['redirectUrl'] : '';

    $this->logDev('Resposta recebida do POST de consentimento', [
      'responseCode' => $responseCode,
      'redirectUrl' => $redirectUrl,
      'rawBody' => $respRawBody,
    ]);

    if ($redirectUrl === '') {
      $this->logDev('Falha: redirectUrl não retornada no body de consentimento');
      return [
        'success' => false,
        'trace' => 'subscription_select',
      ];
    }

    $allCookies = $this->mergeResponseCookies($savedCookies, $response);
    $this->logDev('Consentimento aprovado com sucesso', [
      'redirectUrl' => $redirectUrl,
      'totalCookies' => count($allCookies),
    ]);

    return [
      'success' => true,
      'completed' => false,
      'step' => 'finalize',
      'message' => 'Autenticação com a FULL realizada. Finalizando ativação local...',
      'state' => [
        'redirectUrl' => $redirectUrl,
        'cookies' => $allCookies,
      ]
    ];
  }

  private function finalizeElementorActivation(string $redirectUrl, array $savedCookies): array
  {
    if ($redirectUrl === '') {
      $this->logDev('Falha em finalizeElementorActivation: redirectUrl vazia');
      return [
        'success' => false,
        'trace' => 'missing_redirect_url',
      ];
    }

    $this->logDev('Iniciando loop de redirecionamentos em finalizeElementorActivation', [
      'initialUrl' => $redirectUrl,
      'cookiesCount' => count($savedCookies),
    ]);

    $currentUrl = $redirectUrl;
    $cookiesData = $savedCookies;
    $code = '';
    $state = '';
    $maxHops = 6;

    for ($hop = 0; $hop < $maxHops; $hop++) {
      $parsedCurrent = wp_parse_url($currentUrl);
      $currentQuery = [];
      if (is_array($parsedCurrent) && !empty($parsedCurrent['query'])) {
        wp_parse_str($parsedCurrent['query'], $currentQuery);
      }

      $this->logDev("Hop #{$hop} analisando URL", [
        'url' => $currentUrl,
        'hasCode' => !empty($currentQuery['code']),
      ]);

      if (!empty($currentQuery['code'])) {
        $code = sanitize_text_field((string) $currentQuery['code']);
        $state = isset($currentQuery['state']) ? sanitize_text_field((string) $currentQuery['state']) : '';
        $this->logDev("Código OAuth encontrado na query do hop #{$hop}", ['code' => $code]);
        break;
      }

      $response = wp_remote_get($currentUrl, [
        'cookies' => $this->buildHttpCookies($cookiesData),
        'redirection' => 0,
        'sslverify' => false,
        'timeout' => 30,
      ]);

      if (is_wp_error($response)) {
        $this->logDev("Erro HTTP no hop #{$hop}", ['error' => $response->get_error_message()]);
        return [
          'success' => false,
          'trace' => 'callback_fetch_error',
        ];
      }

      $cookiesData = $this->mergeResponseCookies($cookiesData, $response);

      $location = wp_remote_retrieve_header($response, 'location');
      if (is_array($location)) {
        $location = end($location);
      }
      $location = is_string($location) ? trim($location) : '';

      $responseCode = wp_remote_retrieve_response_code($response);
      $this->logDev("Hop #{$hop} resultado HTTP", [
        'code' => $responseCode,
        'locationHeader' => $location,
      ]);

      if ($location === '') {
        $this->logDev("Hop #{$hop} não retornou cabeçalho Location; interrompendo hops");
        break;
      }

      if (strpos($location, '/') === 0 && is_array($parsedCurrent) && !empty($parsedCurrent['scheme']) && !empty($parsedCurrent['host'])) {
        $location = $parsedCurrent['scheme'] . '://' . $parsedCurrent['host'] . $location;
      }

      $parsedLocation = wp_parse_url($location);
      $locationQuery = [];
      if (is_array($parsedLocation) && !empty($parsedLocation['query'])) {
        wp_parse_str($parsedLocation['query'], $locationQuery);
      }

      if (!empty($locationQuery['code'])) {
        $code = sanitize_text_field((string) $locationQuery['code']);
        $state = isset($locationQuery['state']) ? sanitize_text_field((string) $locationQuery['state']) : '';
        $this->logDev("Código OAuth interceptado no cabeçalho Location", [
          'code' => $code,
          'state' => $state,
          'location' => $location,
        ]);
        break;
      }

      $currentUrl = $location;
    }

    if ($code === '') {
      $this->logDev('Falha: código OAuth não foi capturado em nenhum dos hops');
      return [
        'success' => false,
        'trace' => 'oauth_code_missing',
      ];
    }

    $this->logDev('Executando completeElementorConnectLocally com o código OAuth interceptado');
    $activated = $this->completeElementorConnectLocally($code, $state);
    if (!$activated['success']) {
      $this->logDev('Falha em completeElementorConnectLocally', ['trace' => $activated['trace'] ?? 'unknown']);
      return $activated;
    }

    $this->logDev('Ativação concluída com sucesso total!');

    return [
      'success' => true,
      'completed' => true,
      'message' => 'Plugin ativado com sucesso e pronto para uso! Aproveite.',
      'redirectUrl' => admin_url('admin.php?page=elementor-home'),
    ];
  }

  private function completeElementorConnectLocally(string $code, string $state): array
  {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';

    $this->ensureAdminUserContext();
    $this->logDev('Iniciando completeElementorConnectLocally', [
      'code' => $code,
      'state' => $state,
      'userId' => get_current_user_id(),
    ]);

    if (!class_exists('\ElementorOne\Connect\Facade')) {
      $this->logDev('Falha: classe ElementorOne\Connect\Facade não existe');
      return [
        'success' => false,
        'trace' => 'elementor_one_facade_missing',
      ];
    }

    try {
      $facade = \ElementorOne\Connect\Facade::get('elementor-one');
      if (!$facade) {
        $this->logDev('Falha: Facade::get("elementor-one") retornou null');
        return [
          'success' => false,
          'trace' => 'elementor_one_instance_missing',
        ];
      }

      $grantType = class_exists('\ElementorOne\Connect\Classes\GrantTypes')
        ? \ElementorOne\Connect\Classes\GrantTypes::AUTHORIZATION_CODE
        : 'authorization_code';

      $this->logDev('Solicitando token exchange via Facade->service()->get_token', [
        'grantType' => $grantType,
      ]);

      $tokenResponse = $facade->service()->get_token($grantType, $code);
      $accessToken = is_array($tokenResponse) && !empty($tokenResponse['access_token'])
        ? (string) $tokenResponse['access_token']
        : '';

      $this->logDev('Resposta recebida do token exchange', [
        'hasAccessToken' => !empty($accessToken),
        'tokenKeys' => is_array($tokenResponse) ? array_keys($tokenResponse) : null,
      ]);

      if ($accessToken === '') {
        $this->logDev('Falha: access_token vazio retornado');
        return [
          'success' => false,
          'trace' => 'oauth_token_exchange_empty',
        ];
      }

      $ownerId = $this->resolveAdminUserId();
      $this->logDev('Configurando dados no Facade', ['ownerId' => $ownerId]);

      $facade->data()->set_share_usage_data('yes');
      $facade->data()->set_owner_user_id($ownerId);
      $facade->data()->set_home_url();

      $appPrefix = (string) $facade->get_config('app_prefix');

      if (class_exists('\ElementorOne\Admin\Components\Onboarding')) {
        $onboarding = \ElementorOne\Admin\Components\Onboarding::instance();
        if ($onboarding) {
          remove_action('elementor_one/' . $appPrefix . '_connected', [$onboarding, 'on_connect']);
          remove_action('elementor_one/' . $appPrefix . '_connect_fail', [$onboarding, 'on_connect_fail']);
          $this->logDev('Hooks de redirect do Onboarding removidos com sucesso');
        }
      }

      $this->logDev('Disparando actions elementor_one/connected');
      do_action('elementor_one/connected', $facade);
      do_action('elementor_one/' . $appPrefix . '_connected', $facade);

      if (class_exists('\ElementorOne\Admin\Services\Migration')) {
        $this->logDev('Disparando Migration::instance()->run("elementor-pro", true)');
        try {
          \ElementorOne\Admin\Services\Migration::instance()->run('elementor-pro', true);
          $this->logDev('Migration executada com sucesso');
        } catch (\Throwable $e) {
          $this->logDev('Aviso em Migration::instance()->run', ['error' => $e->getMessage()]);
        }
      }

      $this->logDev('Disparando action elementor_one/update_editor_data');
      do_action('elementor_one/update_editor_data');
      remove_all_actions('elementor_one/update_editor_data');

      $activeKey = get_option('elementor_pro_license_key', '');
      $this->logDev('Verificando elementor_pro_license_key após action', ['hasKey' => !empty($activeKey)]);

      if (empty($activeKey) && class_exists('\ElementorOne\Admin\Services\Editor')) {
        $this->logDev('Tentando fallback via Editor::instance()->update_editor_data("activate", true)');
        \ElementorOne\Admin\Services\Editor::instance()->update_editor_data('activate', true);
        $activeKey = get_option('elementor_pro_license_key', '');
        $this->logDev('Chave após fallback do Editor', ['hasKey' => !empty($activeKey)]);
      }

      if (empty($activeKey)) {
        $this->logDev('Falha: elementor_pro_license_key não foi gravada na tabela wp_options');
        return [
          'success' => false,
          'trace' => 'license_key_not_populated',
        ];
      }

      if (class_exists('\ElementorPro\License\API')) {
        $this->logDev('Executando ElementorPro\License\API::get_license_data(true)');
        \ElementorPro\License\API::get_license_data(true);
      }

      $this->logDev('completeElementorConnectLocally finalizado com sucesso total');

      return [
        'success' => true,
      ];
    } catch (\Throwable $e) {
      $this->logDev('Exceção capturada em completeElementorConnectLocally', [
        'error' => $e->getMessage(),
        'file' => $e->getFile() . ':' . $e->getLine(),
      ]);
      return [
        'success' => false,
        'trace' => 'oauth_token_exchange_failed',
      ];
    }
  }

  public function jetThemeCore(string $license): array
  {
    $cookies = [];
    foreach ($_COOKIE as $name => $value) {
      $cookies[] = new \WP_Http_Cookie([
        'name' => $name,
        'value' => $value
      ]);
    }

    $request = wp_remote_post(admin_url('admin-ajax.php'), [
      'sslverify' => false,
      'cookies' => $cookies,
      'body' => [
        'action' => 'jet_license_action',
        'data' => [
          'license' => $license,
          'action' => 'activate',
          'nonce' => wp_create_nonce('jet-dashboard')
        ]
      ],
    ]);

    if (is_wp_error($request)) {
      return ['success' => false];
    }

    $response = json_decode(wp_remote_retrieve_body($request), true);

    return ['success' => is_array($response) && isset($response['status']) && $response['status'] === 'success'];
  }

  public function essentialAddons(string $license, string $step = '', array $state = []): array
  {
    if (!defined('EAEL_PRO_PLUGIN_FILE')) {
      return ['success' => false];
    }

    $transientKey = 'fc/local-license-processor/essential-addons';

    if (empty($step)) {
      return $this->startEssentialAddonsActivation($license, $transientKey);
    }

    if ($step === 'check_otp') {
      return $this->checkEssentialAddonsOTP($license, $transientKey, $state);
    }

    return ['success' => false];
  }

  private function getEssentialAddonsManager(): \Essential_Addons_Elementor\Pro\Classes\License\Manager
  {
    return \Essential_Addons_Elementor\Pro\Classes\License\Manager::get_instance([
      'plugin_file' => EAEL_PRO_PLUGIN_FILE,
      'version' => EAEL_PRO_PLUGIN_VERSION,
      'item_id' => EAEL_SL_ITEM_ID,
      'item_name' => EAEL_SL_ITEM_NAME,
      'item_slug' => EAEL_SL_ITEM_SLUG,
      'textdomain' => 'essential-addons-elementor',
      'db_prefix' => EAEL_SL_ITEM_SLUG,
      'page_slug' => 'eael-settings',
      'scripts_handle' => 'eael-admin-dashboard',
      'screen_id' => ["toplevel_page_eael-settings"],
      'api' => 'ajax',
      'ajax' => [
        'textdomain' => 'essential-addons-elementor',
        'action_prefix' => 'essential-addons-elementor'
      ],
      'migrate_from' => [
        'license' => 'essential-addons-elementor-license-key',
        'status' => 'essential-addons-elementor-license-status'
      ]
    ]);
  }

  private function startEssentialAddonsActivation(string $license, string $transientKey): array
  {
    delete_transient($transientKey);

    $m = $this->getEssentialAddonsManager();
    $response = $m->activate(['license_key' => $license]);

    if (is_wp_error($response)) {
      return ['success' => false];
    }

    if (!isset($response->license) || 'required_otp' !== $response->license) {
      return ['success' => false];
    }

    return [
      'success' => true,
      'completed' => false,
      'step' => 'check_otp',
      'message' => 'Chave enviada. Aguardando OTP ser gerado...',
      'state' => [
        'attempts' => 0
      ]
    ];
  }

  private function checkEssentialAddonsOTP(string $license, string $transientKey, array $state): array
  {
    global $wpdb;
    $attempts = (int) ($state['attempts'] ?? 0);

    sleep(5);

    $data = $wpdb->get_var("SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = '_transient_{$transientKey}';");
    $data = json_decode($data, true);

    $otp = null;
    if (is_array($data) && isset($data['otp'])) {
      $otp = $data['otp'];
    }

    if (!$otp) {
      if ($attempts >= 24) {
        return [
          'success' => false,
          'message' => 'Tempo esgotado aguardando o código OTP do Essential Addons.'
        ];
      }

      return [
        'success' => true,
        'completed' => false,
        'step' => 'check_otp',
        'message' => 'Aguardando o código OTP do Essential Addons (' . (($attempts + 1) * 5) . 's / 120s)...',
        'state' => [
          'attempts' => $attempts + 1
        ]
      ];
    }

    $m = $this->getEssentialAddonsManager();
    $done = $m->submit_otp([
      'license_key' => $license,
      'otp' => $otp
    ]);

    if (is_wp_error($done)) {
      return ['success' => false];
    }

    $isValid = isset($done->license) && $done->license === 'valid';

    return [
      'success' => $isValid,
      'completed' => true,
      'message' => $isValid ? 'Essential Addons ativado com sucesso!' : 'A ativação com o OTP falhou.',
    ];
  }

  public function rankMath(string $license): array
  {
    if (!function_exists('rank_math_pro')) {
      return ['success' => false];
    }

    [$user, $licenseKey] = explode(':', $license);

    update_option(
      'rank_math_reseller_data',
      [
        'username' => $user,
        'api_key' => $licenseKey,
      ],
      false
    );

    \RankMathPro\Admin\Licence_Activation::activate_licence();

    $data = \RankMath\Admin\Admin_Helper::get_registration_data();

    return ['success' => is_array($data) && $data['connected']];
  }

  public function ultimateAddons(string $license): array
  {
    return $this->bsfFamily('ultimate-elementor', 'uael', $license);
  }

  public function astra(string $license): array
  {
    return $this->bsfFamily('astra-addon', 'astra-addon', $license);
  }

  public function seoPress(string $license): array
  {
    $file = WP_PLUGIN_DIR . '/wp-seopress-pro/inc/admin/callbacks/License.php';

    if (!file_exists($file)) {
      return ['success' => false];
    }

    if (!function_exists('seopress_automatic_license_activation')) {
      include_once($file);
    }

    if (defined('SEOPRESS_LICENSE_KEY')) {
      return ['success' => false];
    }

    delete_option('seopress_pro_license_automatic_attempt');
    define('SEOPRESS_LICENSE_KEY', $license);

    seopress_automatic_license_activation();

    $done = get_option('seopress_pro_license_status') === 'valid';

    if ($done) {
      update_option('seopress_pro_license_key', $license, false);
    }

    return ['success' => $done];
  }

  public function perfmatters(string $license): array
  {
    if (!class_exists('\Perfmatters\License', false)) {
      return ['success' => false];
    }

    update_option('perfmatters_edd_license_key', $license, false);

    return ['success' => \Perfmatters\License::activate()];
  }

  public function happyElementorAddons(string $license): array
  {
    if (!function_exists('hapro_get_appsero')) {
      return ['success' => false];
    }

    hapro_get_appsero()->license()->license_form_submit([
      'license_key' => $license,
      'submit' => 1,
      '_action' => 'active',
      '_nonce' => wp_create_nonce('Happy Elementor Addons Pro')
    ]);

    $license = hapro_get_appsero()->license()->get_license();

    return ['success' => isset($license['status']) && $license['status'] === 'active'];
  }

  public function acfPRO(string $license): array
  {
    if (!function_exists('acf_pro_activate_license')) {
      return ['success' => false];
    }

    $done = acf_pro_activate_license($license, true);

    return ['success' => is_array($done) && (int) $done['success'] === 1];
  }

  public function wpforms(string $license): array
  {
    if (!function_exists('wpforms')) {
      return ['success' => false];
    }

    if (!class_exists('\WPForms_License', false)) {
      $file = WP_CONTENT_DIR . '/plugins/wpforms/pro/includes/admin/class-license.php';

      if (!file_exists($file)) {
        return ['success' => false];
      }

      require_once($file);
    }

    $done = (new \WPForms_License())->verify_key($license, false);

    return ['success' => $done ? true : false];
  }

  public function aiowpspf(string $license): array
  {
    return $this->updraftFamily('all-in-one-wp-security-and-firewall-premium', $license);
  }

  public function updraftplus(string $license): array
  {
    return $this->updraftFamily('updraftplus', $license);
  }

  public function wpOptimize(string $license): array
  {
    return $this->updraftFamily('wp-optimize-premium', $license);
  }

  private function updraftFamily(string $plugin, string $license): array
  {
    [$email, $password] = explode(':', $license);

    $request = wp_remote_post(admin_url('admin-ajax.php'), [
      'cookies' => $_COOKIE,
      'headers' => [
        'Content-Type' => 'application/x-www-form-urlencoded'
      ],
      'sslverify' => false,
      'body' => [
        'action' => 'udmupdater_ajax',
        'subaction' => 'connect',
        'nonce' => wp_create_nonce('udmupdater-ajax-nonce'),
        'userid' => 2,
        'slug' => $plugin,
        'email' => $email,
        'password' => $password
      ]
    ]);

    $response = json_decode(wp_remote_retrieve_body($request), true);

    return ['success' => is_array($response) && isset($response['code']) && $response['code'] === 'OK'];
  }

  public function bsfFamily(string $plugin, string $productId, string $license): array
  {
    $file = WP_PLUGIN_DIR . '/' . $plugin . '/admin/bsf-core/index.php';

    if (!file_exists($file)) {
      return ['success' => false];
    }

    if (!class_exists('BSF_License_Manager', false)) {
      include_once($file);
    }

    $result = \BSF_License_Manager::instance()->bsf_process_license_activation([
      'privacy_consent' => true,
      'terms_conditions_consent' => true,
      'product_id' => $productId,
      'license_key' => $license,
    ]);

    return ['success' => is_array($result) && isset($result['success']) && $result['success']];
  }
}
