<?php
// backend/OneDriveService.php
// OneDrive Business Integration Service using Microsoft Graph API

require_once __DIR__ . '/db.php';

class OneDriveService {
    private $db;
    private $clientId;
    private $clientSecret;
    private $tenantId;
    private $redirectUri;

    public function __construct() {
        $this->db = getDbConnection();
        $this->loadCredentials();
    }

    private function loadCredentials() {
        if (!$this->db) return;

        // Fetch credentials from settings database table
        $stmt = $this->db->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('onedrive_client_id', 'onedrive_client_secret', 'onedrive_tenant_id', 'onedrive_redirect_uri')");
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        $this->clientId = $settings['onedrive_client_id'] ?? '';
        $this->clientSecret = $settings['onedrive_client_secret'] ?? '';
        $this->tenantId = $settings['onedrive_tenant_id'] ?? '';
        $this->redirectUri = $settings['onedrive_redirect_uri'] ?? '';
        if (empty($this->redirectUri)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $this->redirectUri = $protocol . ($_SERVER['HTTP_HOST'] ?? 'tsj.coatskoraput.org') . "/backend/onedrive_callback.php";
        }
    }

    public function isConfigured() {
        return !empty($this->clientId) && !empty($this->clientSecret) && !empty($this->tenantId);
    }

    public function getAuthUrl() {
        $scopes = urlencode("Files.ReadWrite offline_access");
        return "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/authorize?client_id={$this->clientId}&response_type=code&redirect_uri=" . urlencode($this->redirectUri) . "&response_mode=query&scope={$scopes}&state=journal_portal";
    }

    public function tradeCodeForTokens($code) {
        $url = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";
        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ];

        return $this->postCurlRequest($url, $data);
    }

    public function getAccessToken() {
        if (!$this->db) return null;

        // Fetch stored token status
        $accessToken = $this->getSetting('onedrive_access_token');
        $refreshToken = $this->getSetting('onedrive_refresh_token');
        $expiry = intval($this->getSetting('onedrive_token_expiry') ?? 0);

        if (!$accessToken) return null;

        // If expired or expiring soon, refresh it
        if (time() >= ($expiry - 60)) {
            return $this->refreshAccessToken($refreshToken);
        }

        return $accessToken;
    }

    private function refreshAccessToken($refreshToken) {
        if (!$refreshToken) return null;

        $url = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";
        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ];

        $response = $this->postCurlRequest($url, $data);
        if ($response && isset($response['access_token'])) {
            $this->saveSetting('onedrive_access_token', $response['access_token']);
            if (isset($response['refresh_token'])) {
                $this->saveSetting('onedrive_refresh_token', $response['refresh_token']);
            }
            $this->saveSetting('onedrive_token_expiry', strval(time() + intval($response['expires_in'])));
            return $response['access_token'];
        }

        return null;
    }

    // Create a new .docx blank manuscript file on OneDrive Business
    public function createManuscriptFile($title) {
        $token = $this->getAccessToken();
        if (!$token) return null;

        // Fetch custom OneDrive folder name (fallback to Journal_Manuscripts)
        $folder = trim($this->getSetting('onedrive_folder') ?: 'Journal_Manuscripts', '/');

        // Microsoft Graph: Put file in the configured folder
        $fileName = rawurlencode($title . "_" . time() . ".docx");
        $url = "https://graph.microsoft.com/v1.0/me/drive/root:/{$folder}/{$fileName}:/content";
        
        // Load default empty docx file structure content
        $emptyDocxBase64 = "UEsDBBQAAAAIANJkM0sAAAAAAAAAAAAAAAALAAAAd29yZC9kb2N1bWVudC54bWw=...[docx_content]";
        $content = base64_decode($emptyDocxBase64);

        $headers = [
            "Authorization: Bearer {$token}",
            "Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 201 || $httpCode === 200) {
            return json_decode($response, true);
        }

        return null;
    }

    // Generate sharing edit link to embed Word Online inside an iframe
    public function generateEditLink($fileId) {
        $token = $this->getAccessToken();
        if (!$token) return null;

        $url = "https://graph.microsoft.com/v1.0/me/drive/items/{$fileId}/createLink";
        
        // Try creating an anonymous edit link first (so public users don't need a Microsoft account)
        $data = [
            "type" => "edit",
            "scope" => "anonymous"
        ];

        $headers = [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);
        
        // If anonymous is blocked by tenant policies, fallback to organization scope
        if (isset($result['error'])) {
            $data['scope'] = "organization";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($ch);
            curl_close($ch);
            $result = json_decode($response, true);
        }

        if ($result && isset($result['link'])) {
            $webUrl = $result['link']['webUrl'];
            
            // Force direct inline interactive editor mode
            if (strpos($webUrl, '?') !== false) {
                $webUrl .= "&action=embededit";
            } else {
                $webUrl .= "?action=embededit";
            }
            return $webUrl;
        }

        return null;
    }

    // Convert OneDrive Word Document to PDF via Graph API
    public function convertWordToPdf($fileId) {
        $token = $this->getAccessToken();
        if (!$token) return null;

        $url = "https://graph.microsoft.com/v1.0/me/drive/items/{$fileId}/content?format=pdf";
        
        $headers = [
            "Authorization: Bearer {$token}"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $pdfBinary = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return $pdfBinary;
        }

        return null;
    }

    // Curl POST helper
    private function postCurlRequest($url, $fields) {
        $postParams = http_build_query($fields);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postParams);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/x-www-form-urlencoded"]);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    // Settings helpers
    public function getSetting($key) {
        $stmt = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn() ?: null;
    }

    public function saveSetting($key, $value) {
        $stmt = $this->db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$key, $value, $value]);
    }
}
?>
