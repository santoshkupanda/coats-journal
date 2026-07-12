<?php
// backend/onedrive_callback.php
// Receives Microsoft Graph OAuth auth-codes and registers tokens

require_once __DIR__ . '/OneDriveService.php';

$code = $_GET['code'] ?? null;
$error = $_GET['error'] ?? null;

if ($error) {
    die("Microsoft Authentication Error: " . htmlspecialchars($error));
}

if ($code) {
    $service = new OneDriveService();
    $response = $service->tradeCodeForTokens($code);

    if ($response && isset($response['access_token'])) {
        // Save retrieved access, refresh and expiry settings
        $service->saveSetting('onedrive_access_token', $response['access_token']);
        if (isset($response['refresh_token'])) {
            $service->saveSetting('onedrive_refresh_token', $response['refresh_token']);
        }
        $service->saveSetting('onedrive_token_expiry', strval(time() + intval($response['expires_in'])));
        
        // Show success screen and redirect to portal dashboard
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Microsoft OneDrive Synced</title>
            <style>
                body {
                    background-color: #0f172a;
                    color: white;
                    font-family: -apple-system, sans-serif;
                    display: flex;
                    height: 100vh;
                    justify-content: center;
                    align-items: center;
                    margin: 0;
                }
                .card {
                    background-color: rgba(255,255,255,0.05);
                    border: 1px solid rgba(255,255,255,0.1);
                    padding: 32px;
                    border-radius: 16px;
                    text-align: center;
                    max-width: 400px;
                    box-shadow: 0 4px 30px rgba(0,0,0,0.5);
                    backdrop-filter: blur(10px);
                }
                .icon {
                    width: 64px;
                    height: 64px;
                    background-color: #107c41;
                    color: white;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 32px;
                    margin: 0 auto 16px;
                }
                h1 { font-size: 20px; margin-bottom: 8px; }
                p { font-size: 13px; color: #94a3b8; margin-bottom: 24px; }
                .btn {
                    background-color: #106ebe;
                    color: white;
                    padding: 10px 24px;
                    text-decoration: none;
                    font-size: 13px;
                    font-weight: 600;
                    border-radius: 6px;
                    transition: background-color 0.2s;
                }
                .btn:hover { background-color: #005a9e; }
            </style>
        </head>
        <body>
            <div class="card">
                <div class="icon">✓</div>
                <h1>OneDrive Connected Successfully</h1>
                <p>Microsoft OneDrive Business has been integrated. You will be redirected back to the portal in 3 seconds...</p>
                <a href="../index.html" class="btn">Return to Dashboard</a>
            </div>
            <script>
                setTimeout(() => {
                    window.location.href = "../index.html?oauth_success=true";
                }, 3000);
            </script>
        </body>
        </html>
        <?php
        exit();
    } else {
        die("Token registration failure. Details: " . json_encode($response));
    }
} else {
    die("Error: No authentication code provided.");
}
?>
