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
        $emptyDocxBase64 = "UEsDBBQAAAAIAI9C7Vx5bjPX6AAAAK0BAAATAAAAW0NvbnRlbnRfVHlwZXNdLnhtbH1QyU7DMBD9FWuuKHHggBCK0wPLETiUDxjZk8SqN3nc0v49Tlt6QIXjzFv1+tXeO7GjzDYGBbdtB4KCjsaGScHn+rV5AMEFg0EXAyk4EMNq6NeHRCyqNrCCuZT0KCXrmTxyGxOFiowxeyz1zJNMqDc4kbzrunupYygUSlMWDxj6Zxpx64p42df3qUcmxyCeTsQlSwGm5KzGUnG5C+ZXSnNOaKvyyOHZJr6pBJBXExbk74Cz7r0Ok60h8YG5vKGvLPkVs5Em6q2vyvZ/mys94zhaTRf94pZy1MRcF/euvSAebfjpL49zD99QSwMEFAAAAAgAj0LtXJv9N+qtAAAAKQEAAAsAAABfcmVscy8ucmVsc43POw7CMAwG4KtE3mlaBoRQ0y4IqSsqB7ASN61oHkrCo7cnAwNFDIy2f3+W6/ZpZnanECdnBVRFCYysdGqyWsClP232wGJCq3B2lgQsFKFt6jPNmPJKHCcfWTZsFDCm5A+cRzmSwVg4TzZPBhcMplwGzT3KK2ri27Lc8fBpwNpknRIQOlUB6xdP/9huGCZJRydvhmz6ceIrkWUMmpKAhwuKq3e7yCzwpuarF5sXUEsDBBQAAAAIAI9C7Vx5N8bmlQAAAMkAAAARAAAAd29yZC9kb2N1bWVudC54bWxFjkEKwjAQRa9SZm9TXYiUpu48gR4gJmNbaGZCJlp7e5OKuHmf4X8e053ffq5eGGVi0rCvG6iQLLuJBg2362V3gkqSIWdmJtSwosC575bWsX16pFRlAUm7aBhTCq1SYkf0RmoOSLl7cPQm5TMOauHoQmSLItnvZ3VomqPyZiIoyju7tWQoiAWp79SPcWPY+F2q/xf9B1BLAQIUABQAAAAIAI9C7Vx5bjPX6AAAAK0BAAATAAAAAAAAAAAAAAAAAAAAAABbQ29udGVudF9UeXBlc10ueG1sUEsBAhQAFAAAAAgAj0LtXJv9N+qtAAAAKQEAAAsAAAAAAAAAAAAAAAAAGQEAAF9yZWxzLy5yZWxzUEsBAhQAFAAAAAgAj0LtXHk3xuaVAAAAyQAAABEAAAAAAAAAAAAAAAAA7wEAAHdvcmQvZG9jdW1lbnQueG1sUEsFBgAAAAADAAMAuQAAALMCAAAAAA==";
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

    public function updateManuscriptFileContent($fileId, $htmlContent) {
        if (empty($fileId)) return false;
        
        $token = $this->getAccessToken();
        if (!$token) return false;

        $docxBinary = $this->htmlToDocx($htmlContent);
        if (!$docxBinary) return false;

        $url = "https://graph.microsoft.com/v1.0/me/drive/items/{$fileId}/content";
        $headers = [
            "Authorization: Bearer {$token}",
            "Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $docxBinary);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode === 200 || $httpCode === 204);
    }

    private function htmlToDocx($html) {
        // Strip tags we don't want, clean up whitespace
        $html = str_replace(["\r", "\n"], "", $html);
        
        $wml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $wml .= '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">';
        $wml .= '<w:body>';
        
        $tokens = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        
        $inParagraph = false;
        $inBold = false;
        $inItalic = false;
        $runs = [];
        
        foreach ($tokens as $token) {
            if (strpos($token, '<') === 0) {
                $tag = strtolower(trim($token, '<>'));
                if ($tag === 'p' || $tag === 'h1' || $tag === 'h2' || $tag === 'h3' || $tag === 'div') {
                    if ($inParagraph) {
                        $wml .= $this->buildParagraphWml($runs);
                        $runs = [];
                    }
                    $inParagraph = true;
                } elseif ($tag === '/p' || $tag === '/h1' || $tag === '/h2' || $tag === '/h3' || $tag === '/div') {
                    if ($inParagraph) {
                        $wml .= $this->buildParagraphWml($runs);
                        $runs = [];
                        $inParagraph = false;
                    }
                } elseif ($tag === 'b' || $tag === 'strong') {
                    $inBold = true;
                } elseif ($tag === '/b' || $tag === '/strong') {
                    $inBold = false;
                } elseif ($tag === 'i' || $tag === 'em') {
                    $inItalic = true;
                } elseif ($tag === '/i' || $tag === '/em') {
                    $inItalic = false;
                } elseif ($tag === 'br') {
                    $runs[] = ['type' => 'break'];
                }
            } else {
                if (!$inParagraph) {
                    $inParagraph = true;
                }
                $runs[] = [
                    'type' => 'text',
                    'text' => htmlspecialchars($token, ENT_XML1, 'UTF-8'),
                    'bold' => $inBold,
                    'italic' => $inItalic
                ];
            }
        }
        
        if ($inParagraph && !empty($runs)) {
            $wml .= $this->buildParagraphWml($runs);
        }
        
        $wml .= '</w:body>';
        $wml .= '</w:document>';
        
        if (!class_exists('ZipArchive')) {
            // If ZipArchive is missing, fallback to returning the XML body (Word will open it as single file XML docx in desktop)
            return $wml;
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>';
            $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
            
            $zip->addFromString('[Content_Types].xml', $content_types);
            $zip->addFromString('_rels/.rels', $rels);
            $zip->addFromString('word/document.xml', $wml);
            $zip->close();
            
            $binary = file_get_contents($tempFile);
            unlink($tempFile);
            return $binary;
        }
        
        return $wml;
    }
    
    private function buildParagraphWml($runs) {
        $para = '<w:p>';
        foreach ($runs as $run) {
            if ($run['type'] === 'break') {
                $para .= '<w:r><w:br/></w:r>';
            } else {
                $para .= '<w:r>';
                if ($run['bold'] || $run['italic']) {
                    $para .= '<w:rPr>';
                    if ($run['bold']) $para .= '<w:b/>';
                    if ($run['italic']) $para .= '<w:i/>';
                    $para .= '</w:rPr>';
                }
                $para .= '<w:t>' . $run['text'] . '</w:t>';
                $para .= '</w:r>';
            }
        }
        $para .= '</w:p>';
        return $para;
    }
}
?>
