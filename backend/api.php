<?php
// backend/api.php
// Main API Endpoints routing file for COATS Journal Portal

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/OneDriveService.php';

header("Content-Type: application/json");

$action = $_GET['action'] ?? '';
$db = getDbConnection();

if (!$db) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit();
}

$odService = new OneDriveService();

// Parse incoming request body
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? [];

switch ($action) {
    
    // ==================== USER AUTHS & DIRECTORY ====================
    case 'login':
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            echo json_encode(['status' => 'success', 'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'permissions' => [
                    'can_submit' => (bool)$user['can_submit'],
                    'can_review' => (bool)$user['can_review'],
                    'can_assign' => (bool)$user['can_assign'],
                    'can_approve' => (bool)$user['can_approve'],
                    'can_publish' => (bool)$user['can_publish']
                ]
            ]]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Invalid email or password credentials.']);
        }
        break;

    case 'register':
        $username = $input['username'] ?? '';
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        $role = $input['role'] ?? 'Contributor';
        
        // Map standard permissions depending on the selected role
        $can_submit = ($role === 'Contributor' || $role === 'Super Admin' || $role === 'Editor-In-Chief') ? 1 : 0;
        $can_review = ($role === 'Reviewer' || $role === 'Super Admin' || $role === 'Editorial Board' || $role === 'Editor-In-Chief') ? 1 : 0;
        $can_assign = ($role === 'Managing Editor' || $role === 'Super Admin' || $role === 'Editor-In-Chief') ? 1 : 0;
        $can_approve = ($role === 'Editorial Board' || $role === 'Managing Editor' || $role === 'Editor-In-Chief' || $role === 'Super Admin') ? 1 : 0;
        $can_publish = ($role === 'Production Staff' || $role === 'Super Admin') ? 1 : 0;

        try {
            $stmt = $db->prepare("INSERT INTO users (username, email, password, role, can_submit, can_review, can_assign, can_approve, can_publish) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $role,
                $can_submit,
                $can_review,
                $can_assign,
                $can_approve,
                $can_publish
            ]);
            echo json_encode(['status' => 'success', 'message' => 'User registered successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'error', 'message' => 'Username or email already exists.']);
        }
        break;

    case 'get_users':
        $stmt = $db->query("SELECT id, username, email, role FROM users");
        echo json_encode(['status' => 'success', 'users' => $stmt->fetchAll()]);
        break;

    // ==================== MANUSCRIPT SUBMISSIONS ====================
    case 'create_manuscript':
        $title = $input['title'] ?? 'Untitled Manuscript';
        $author_id = $input['author_id'] ?? null;

        if (!$author_id) {
            echo json_encode(['status' => 'error', 'message' => 'Author ID required.']);
            break;
        }

        $fileId = '';
        $onedriveUrl = '';

        // If Microsoft Graph is configured, create file on OneDrive
        if ($odService->isConfigured()) {
            $odFile = $odService->createManuscriptFile($title);
            if ($odFile && isset($odFile['id'])) {
                $fileId = $odFile['id'];
                $onedriveUrl = $odService->generateEditLink($fileId) ?? '';
            }
        }

        // If no OneDrive link was generated, fallback to local simulator mode
        if (empty($onedriveUrl)) {
            $onedriveUrl = 'mock_word_online.html';
        }

        $stmt = $db->prepare("INSERT INTO manuscripts (title, author_id, onedrive_file_id, onedrive_url, status) VALUES (?, ?, ?, ?, 'Draft')");
        $stmt->execute([$title, $author_id, $fileId, $onedriveUrl]);
        $manuscriptId = $db->lastInsertId();

        echo json_encode([
            'status' => 'success',
            'manuscript' => [
                'id' => $manuscriptId,
                'title' => $title,
                'onedrive_url' => $onedriveUrl,
                'status' => 'Draft'
            ]
        ]);
        break;

    case 'get_manuscripts':
        $userId = $_GET['user_id'] ?? null;
        $role = $_GET['role'] ?? '';

        if ($role === 'Contributor' && $userId) {
            $stmt = $db->prepare("SELECT m.*, u.username as author_name FROM manuscripts m JOIN users u ON m.author_id = u.id WHERE m.author_id = ?");
            $stmt->execute([$userId]);
        } else {
            $stmt = $db->query("SELECT m.*, u.username as author_name FROM manuscripts m JOIN users u ON m.author_id = u.id");
        }
        echo json_encode(['status' => 'success', 'manuscripts' => $stmt->fetchAll()]);
        break;

    case 'update_metadata':
        $id = $input['id'] ?? null;
        $keywords = $input['keywords'] ?? '';
        $abstract = $input['abstract'] ?? '';
        $content = $input['content'] ?? '';
        $status = $input['status'] ?? 'Draft';

        if (!$id) {
            echo json_encode(['status' => 'error', 'message' => 'Manuscript ID required.']);
            break;
        }

        $stmt = $db->prepare("UPDATE manuscripts SET keywords = ?, abstract = ?, content = ?, status = ? WHERE id = ?");
        $stmt->execute([$keywords, $abstract, $content, $status, $id]);

        // Update OneDrive document in the background if configured
        if ($odService->isConfigured()) {
            $stmt = $db->prepare("SELECT onedrive_file_id FROM manuscripts WHERE id = ?");
            $stmt->execute([$id]);
            $fileId = $stmt->fetchColumn();
            if (!empty($fileId)) {
                $odService->updateManuscriptFileContent($fileId, $content);
            }
        }

        echo json_encode(['status' => 'success', 'message' => 'Manuscript updated successfully.']);
        break;

    // ==================== PEER REVIEW SYSTEM ====================
    case 'assign_reviewer':
        $manuscript_id = $input['manuscript_id'] ?? null;
        $reviewer_id = $input['reviewer_id'] ?? null;

        if (!$manuscript_id || !$reviewer_id) {
            echo json_encode(['status' => 'error', 'message' => 'Missing manuscript or reviewer selection.']);
            break;
        }

        // Add review log
        $stmt = $db->prepare("INSERT INTO reviews (manuscript_id, reviewer_id) VALUES (?, ?)");
        $stmt->execute([$manuscript_id, $reviewer_id]);

        // Update manuscript status
        $stmt = $db->prepare("UPDATE manuscripts SET status = 'In Review' WHERE id = ?");
        $stmt->execute([$manuscript_id]);

        echo json_encode(['status' => 'success', 'message' => 'Reviewer assigned. Status set to In Review.']);
        break;

    case 'submit_review':
        $review_id = $input['review_id'] ?? null;
        $comments = $input['comments'] ?? '';
        $recommendation = $input['recommendation'] ?? ''; // Accept, Revisions Required, Reject

        if (!$review_id) {
            echo json_encode(['status' => 'error', 'message' => 'Review ID required.']);
            break;
        }

        // Update review recommendation
        $stmt = $db->prepare("UPDATE reviews SET comments = ?, recommendation = ? WHERE id = ?");
        $stmt->execute([$comments, $recommendation, $review_id]);

        // Find manuscript id
        $stmt = $db->prepare("SELECT manuscript_id FROM reviews WHERE id = ?");
        $stmt->execute([$review_id]);
        $manuscript_id = $stmt->fetchColumn();

        // Update manuscript status based on decision
        $newStatus = ($recommendation === 'Revisions Required') ? 'Revisions Required' : 'Editorial Board';
        if ($recommendation === 'Reject') {
            $newStatus = 'Rejected';
        }

        $stmt = $db->prepare("UPDATE manuscripts SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $manuscript_id]);

        echo json_encode(['status' => 'success', 'message' => 'Review submitted. Stage routed.']);
        break;

    case 'get_reviews':
        $reviewer_id = $_GET['reviewer_id'] ?? null;
        if ($reviewer_id) {
            $stmt = $db->prepare("SELECT r.*, m.title, m.abstract, m.onedrive_url, u.username as author_name FROM reviews r JOIN manuscripts m ON r.manuscript_id = m.id JOIN users u ON m.author_id = u.id WHERE r.reviewer_id = ?");
            $stmt->execute([$reviewer_id]);
        } else {
            $stmt = $db->query("SELECT r.*, m.title, u.username as reviewer_name FROM reviews r JOIN users u ON r.reviewer_id = u.id");
        }
        echo json_encode(['status' => 'success', 'reviews' => $stmt->fetchAll()]);
        break;

    // ==================== PIPELINE STAGE APPROVALS ====================
    case 'log_pipeline_action':
        $manuscript_id = $input['manuscript_id'] ?? null;
        $stage = $input['stage'] ?? ''; // Editorial Board, Managing Editor, Editor-In-Chief
        $action = $input['action'] ?? ''; // Forward, Return, Approve
        $remarks = $input['remarks'] ?? '';
        $actor_id = $input['actor_id'] ?? null;

        if (!$manuscript_id || !$actor_id) {
            echo json_encode(['status' => 'error', 'message' => 'Manuscript ID and Actor ID required.']);
            break;
        }

        // Insert log
        $stmt = $db->prepare("INSERT INTO pipeline_logs (manuscript_id, stage, action, remarks, actor_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$manuscript_id, $stage, $action, $remarks, $actor_id]);

        // Map next pipeline status
        $newStatus = $stage;
        if ($action === 'Forward') {
            if ($stage === 'Editorial Board') $newStatus = 'Managing Editor';
            if ($stage === 'Managing Editor') $newStatus = 'Editor-In-Chief';
        } elseif ($action === 'Approve' && $stage === 'Editor-In-Chief') {
            $newStatus = 'Approved';
        } elseif ($action === 'Return') {
            $newStatus = 'Revisions Required';
        }

        $stmt = $db->prepare("UPDATE manuscripts SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $manuscript_id]);

        echo json_encode(['status' => 'success', 'message' => "Pipeline stage action logged. Stage set to {$newStatus}."]);
        break;

    // ==================== SUPER ADMIN SETUP CONTROLS ====================
    case 'save_api_settings':
        $clientId = $input['client_id'] ?? '';
        $clientSecret = $input['client_secret'] ?? '';
        $tenantId = $input['tenant_id'] ?? '';
        $onedriveFolder = $input['onedrive_folder'] ?? 'Journal_Manuscripts';
        $redirectUri = $input['redirect_uri'] ?? '';

        if (empty($redirectUri)) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $redirectUri = $protocol . $_SERVER['HTTP_HOST'] . "/backend/onedrive_callback.php";
        }

        $odService->saveSetting('onedrive_client_id', $clientId);
        $odService->saveSetting('onedrive_client_secret', $clientSecret);
        $odService->saveSetting('onedrive_tenant_id', $tenantId);
        $odService->saveSetting('onedrive_folder', $onedriveFolder);
        $odService->saveSetting('onedrive_redirect_uri', $redirectUri);

        echo json_encode(['status' => 'success', 'message' => 'OneDrive API credentials updated successfully.']);
        break;

    case 'get_api_settings':
        echo json_encode([
            'status' => 'success',
            'settings' => [
                'client_id' => $odService->getSetting('onedrive_client_id') ?? '',
                'client_secret' => $odService->getSetting('onedrive_client_secret') ?? '',
                'tenant_id' => $odService->getSetting('onedrive_tenant_id') ?? '',
                'onedrive_folder' => $odService->getSetting('onedrive_folder') ?? 'Journal_Manuscripts',
                'redirect_uri' => $odService->getSetting('onedrive_redirect_uri') ?? '',
                'is_connected' => !empty($odService->getSetting('onedrive_access_token')),
                'auth_url' => $odService->isConfigured() ? $odService->getAuthUrl() : ''
            ]
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Action path not recognized.']);
        break;
}
?>
