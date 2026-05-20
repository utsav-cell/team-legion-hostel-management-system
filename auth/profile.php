<?php
// ─────────────────────────────────────────────────
// auth/profile.php — Profile view + edit
// Self-view: any logged-in role can edit their own photo/cover/description.
// Staff-view: admin/warden/owner can view another user's profile via
//             ?id=N and remove their photo or cover.
// ─────────────────────────────────────────────────

require_once '../db.php';

$auth = get_auth();
if (!$auth) {
    header('Location: login.php');
    exit;
}

$viewer_id   = (int)$auth['id'];
$viewer_role = $auth['role'];

// Target user — either ?id=N (staff viewing) or self
$target_id = (int)($_GET['id'] ?? 0);
if ($target_id <= 0) $target_id = $viewer_id;

$is_self      = ($target_id === $viewer_id);
$can_moderate = in_array($viewer_role, ['admin', 'warden', 'owner'], true);

// Permission check for cross-user view
if (!$is_self && !$can_moderate) {
    http_response_code(403);
    die('You do not have permission to view this profile.');
}

$success = trim($_GET['msg'] ?? '');
$error   = trim($_GET['err'] ?? '');

// ── Upload helpers ─────────────────────────────────────────────────
$upload_dir = __DIR__ . '/../uploads/students/';
if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0755, true); }

function profile_save_image(array $file, int $uid, string $kind): string {
    global $upload_dir;
    $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $allowed_ext  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $max_bytes    = 5 * 1024 * 1024; // 5 MB

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception('Upload failed.');
    if ($file['size'] > $max_bytes) throw new Exception('File too large (max 5 MB).');

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed_mime, true)) throw new Exception('Invalid image format.');

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) $ext = 'jpg';

    $filename = $kind . '_' . $uid . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
        throw new Exception('Could not save uploaded file.');
    }
    return $filename;
}

// ── Handle POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        csrf_verify($_POST['csrf_token'] ?? '');
        $action = $_POST['action'] ?? '';

        // Self-edit actions
        if ($is_self && $action === 'update_profile') {
            $changes = [];
            $photo_changed = false;
            // Profile photo
            if (!empty($_FILES['photo']['name'])) {
                $new_photo = profile_save_image($_FILES['photo'], $target_id, 'avatar');
                if ($new_photo) {
                    $pdo->prepare("UPDATE users SET photo = ? WHERE id = ?")
                        ->execute([$new_photo, $target_id]);
                    $_SESSION['user_photo'] = $new_photo;
                    $changes[] = 'profile photo';
                    $photo_changed = true;
                }
            }
            // Cover photo
            if (!empty($_FILES['cover_photo']['name'])) {
                $new_cover = profile_save_image($_FILES['cover_photo'], $target_id, 'cover');
                if ($new_cover) {
                    $pdo->prepare("UPDATE users SET cover_photo = ? WHERE id = ?")
                        ->execute([$new_cover, $target_id]);
                    $changes[] = 'cover photo';
                }
            }
            // Description
            $description = trim($_POST['description'] ?? '');
            if (mb_strlen($description) > 1000) {
                throw new Exception('Description too long (max 1000 characters).');
            }
            $pdo->prepare("UPDATE users SET description = ? WHERE id = ?")
                ->execute([$description !== '' ? $description : null, $target_id]);
            $changes[] = 'bio';

            // The topbar/sidebar read user data from the signed JWT cookie, not
            // the DB. Re-issue the cookie so the new photo + name appear in the
            // chrome immediately without forcing a re-login.
            if ($photo_changed) refresh_auth_cookie($target_id);

            $success = 'Updated ' . implode(' and ', $changes) . '.';
            // Redirect so the freshly-issued JWT cookie is sent on the next
            // request — same-request reads still see the old cookie value.
            header('Location: profile.php?msg=' . urlencode($success));
            exit;
        } elseif ($is_self && $action === 'remove_photo') {
            $pdo->prepare("UPDATE users SET photo = 'default.png' WHERE id = ?")->execute([$target_id]);
            $_SESSION['user_photo'] = 'default.png';
            refresh_auth_cookie($target_id);
            header('Location: profile.php?msg=' . urlencode('Profile photo removed.'));
            exit;
        } elseif ($is_self && $action === 'remove_cover') {
            $pdo->prepare("UPDATE users SET cover_photo = NULL WHERE id = ?")->execute([$target_id]);
            header('Location: profile.php?msg=' . urlencode('Cover photo removed.'));
            exit;
        }

        // Staff moderation actions (admin/warden/owner removing another user's media)
        elseif (!$is_self && $can_moderate && $action === 'mod_remove_photo') {
            $pdo->prepare("UPDATE users SET photo = 'default.png' WHERE id = ?")->execute([$target_id]);
            $success = 'Profile photo removed.';
        } elseif (!$is_self && $can_moderate && $action === 'mod_remove_cover') {
            $pdo->prepare("UPDATE users SET cover_photo = NULL WHERE id = ?")->execute([$target_id]);
            $success = 'Cover image removed.';
        }
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

// ── Load target user state ─────────────────────────────────────────
$me_stmt = $pdo->prepare("SELECT id, name, email, photo, cover_photo, description, role, created_at FROM users WHERE id = ?");
$me_stmt->execute([$target_id]);
$me = $me_stmt->fetch();
if (!$me) {
    http_response_code(404);
    die('User not found.');
}

$target_name = $me['name'];
$photo       = $me['photo']       ?? 'default.png';
$cover_photo = $me['cover_photo'] ?? null;
$description = $me['description'] ?? '';
// Only point the URL at a photo we can actually see on disk. Otherwise we'd
// render a broken <img> that flashes briefly before the onerror fallback,
// and users sometimes see "no photo" right after uploading because of that.
$photo_on_disk = ($photo && $photo !== 'default.png' && is_file($upload_dir . $photo));
$cover_on_disk = ($cover_photo && is_file($upload_dir . $cover_photo));
$photo_url   = $photo_on_disk ? '../uploads/students/' . rawurlencode($photo) : null;
$cover_url   = $cover_on_disk ? '../uploads/students/' . rawurlencode($cover_photo) : null;

$parts    = array_values(array_filter(explode(' ', trim($target_name))));
$initials = strtoupper(substr($parts[0] ?? 'U', 0, 1)) . strtoupper(substr($parts[1] ?? '', 0, 1));

// Which sidebar to render — pick a "dashboard" link based on viewer's role
$dash_map = [
    'student' => 'student/dashboard.php',
    'warden'  => 'warden/dashboard.php',
    'owner'   => 'owner/dashboard.php',
    'admin'   => 'admin/dashboard.php',
];
$dash_target = $dash_map[$viewer_role] ?? 'home/index.php';

// Back-link logic: if viewing someone else, link back to the appropriate list
$back_url   = '../' . $dash_target;
$back_label = 'Back to dashboard';
if (!$is_self) {
    if ($viewer_role === 'admin')      { $back_url = '../admin/manage_users.php';   $back_label = 'Back to users'; }
    elseif ($viewer_role === 'warden') { $back_url = '../warden/list_student.php';  $back_label = 'Back to students'; }
    elseif ($viewer_role === 'owner')  { $back_url = '../owner/manage_staff.php';   $back_label = 'Back to staff'; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_self ? 'My Profile' : e($target_name) . ' — Profile' ?> — HMS</title>
    <link rel="stylesheet" href="../css/style.css?v=34">
    <style>
        .profile-wrap { max-width: 760px; margin: 0 auto; padding: 1.5rem 1rem 4rem; }
        .profile-back { display: inline-flex; align-items: center; gap: 0.4rem; color: var(--muted); font-size: 0.85rem; font-weight: 600; text-decoration: none; margin-bottom: 1rem; }
        .profile-back:hover { color: var(--primary); }
        .profile-back svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2.5; }

        .profile-card {
            background: var(--panel);
            border: 1.5px solid var(--border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-soft);
        }

        /* Cover band */
        .profile-cover {
            height: 180px;
            background: linear-gradient(135deg, var(--primary-soft) 0%, rgba(99,102,241,0.18) 100%);
            background-size: cover;
            background-position: center;
            position: relative;
        }
        html[data-theme="dark"] .profile-cover {
            background: linear-gradient(135deg, rgba(99,102,241,0.22) 0%, rgba(99,102,241,0.08) 100%);
        }
        .profile-cover.has-img { background-image: var(--cover-img); }

        /* Avatar overlapping the cover */
        .profile-avatar-wrap {
            position: relative;
            margin-top: -52px;
            padding: 0 1.5rem;
            display: flex; align-items: flex-end; gap: 1rem;
        }
        .profile-avatar {
            width: 104px; height: 104px;
            border-radius: 50%;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: 2rem; font-weight: 800;
            display: inline-flex; align-items: center; justify-content: center;
            border: 4px solid var(--panel);
            box-shadow: 0 6px 16px rgba(15,23,42,0.16);
            overflow: hidden;
            flex-shrink: 0;
        }
        .profile-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .profile-id-text { padding-bottom: 0.4rem; min-width: 0; }
        .profile-id-text h1 { font-family: 'Bricolage Grotesque', 'Plus Jakarta Sans', sans-serif; font-size: 1.45rem; font-weight: 700; color: var(--text); margin: 0 0 0.2rem; letter-spacing: -0.02em; }
        .profile-id-text .meta { font-size: 0.85rem; color: var(--muted); }
        .profile-id-text .meta .role-pill {
            display: inline-block;
            padding: 0.18rem 0.55rem;
            background: var(--primary-soft);
            color: var(--primary);
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-left: 0.3rem;
        }

        .profile-body { padding: 1.5rem; }
        .profile-section + .profile-section { margin-top: 1.75rem; padding-top: 1.75rem; border-top: 1px solid var(--border); }
        .profile-section h2 { font-size: 0.95rem; font-weight: 800; color: var(--text); margin: 0 0 0.85rem; letter-spacing: -0.01em; }
        .profile-section .helper { font-size: 0.82rem; color: var(--muted); margin-bottom: 0.85rem; line-height: 1.55; }

        .file-row { display: flex; align-items: center; gap: 0.85rem; flex-wrap: wrap; }
        .file-preview {
            width: 76px; height: 76px;
            border-radius: 12px;
            background: var(--panel-alt);
            border: 1.5px dashed var(--border);
            background-size: cover; background-position: center;
            flex-shrink: 0;
        }
        .file-preview.has-img { border-style: solid; }
        .file-preview.cover { width: 130px; height: 70px; }
        input[type="file"] { font-size: 0.85rem; }

        .profile-actions { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-top: 1.25rem; }
        .btn-primary, .btn-ghost {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.55rem 1.1rem;
            border-radius: 9px;
            font-size: 0.88rem; font-weight: 700;
            cursor: pointer; font-family: inherit;
            transition: all 0.15s ease;
        }
        .btn-primary { background: var(--primary); color: #fff; border: 1.5px solid var(--primary); }
        .btn-primary:hover { background: var(--primary-strong); border-color: var(--primary-strong); }
        .btn-ghost { background: transparent; color: var(--text); border: 1.5px solid var(--border); }
        .btn-ghost:hover { background: var(--panel-alt); border-color: var(--muted); }
        .btn-ghost.danger { color: #dc2626; border-color: rgba(239,68,68,0.3); }
        .btn-ghost.danger:hover { background: rgba(239,68,68,0.08); border-color: #dc2626; }

        /* Description display (read-only) */
        .bio-block {
            background: var(--panel-alt);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.85rem 1rem;
            font-size: 0.875rem;
            color: var(--text);
            line-height: 1.6;
            white-space: pre-wrap;
        }
        .bio-empty {
            font-size: 0.85rem;
            color: var(--muted);
            font-style: italic;
        }
        textarea.bio-input {
            min-height: 110px;
            resize: vertical;
            font-family: inherit;
            font-size: 0.875rem;
            line-height: 1.55;
        }

        /* Moderation banner (staff viewing someone else) */
        .mod-banner {
            display: flex;
            align-items: flex-start;
            gap: 0.7rem;
            padding: 0.8rem 1rem;
            background: var(--primary-soft);
            border: 1px solid rgba(99, 102, 241, 0.22);
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: var(--text);
            line-height: 1.45;
        }
        .mod-banner strong { color: var(--primary); }
        html[data-theme="dark"] .mod-banner {
            background: rgba(99, 102, 241, 0.14);
            border-color: rgba(99, 102, 241, 0.32);
        }
    </style>
</head>
<body>
<?= render_sidebar('profile.php', '../' . $viewer_role . '/') ?>
<?= render_topbar() ?>

<div class="container">
    <div class="profile-wrap">
        <a href="<?= e($back_url) ?>" class="profile-back">
            <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            <?= e($back_label) ?>
        </a>

        <?php if ($success): ?>
            <div class="alert alert-success" style="margin-bottom:1rem;"><?= e($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error" style="margin-bottom:1rem;"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (!$is_self): ?>
            <div class="mod-banner">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;margin-top:1px;color:var(--primary);">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <div>You are viewing this profile as <strong><?= e(ucfirst($viewer_role)) ?></strong>. You can remove uploaded media but cannot edit personal details.</div>
            </div>
        <?php endif; ?>

        <div class="profile-card">
            <div class="profile-cover <?= $cover_url ? 'has-img' : '' ?>"
                 <?php if ($cover_url): ?>style="--cover-img: url('<?= e($cover_url) ?>');"<?php endif; ?>></div>

            <div class="profile-avatar-wrap">
                <div class="profile-avatar">
                    <?php if ($photo_url): ?>
                        <img src="<?= e($photo_url) ?>" alt="<?= e($target_name) ?>" onerror="this.parentNode.textContent='<?= e($initials) ?>'">
                    <?php else: ?><?= e($initials) ?><?php endif; ?>
                </div>
                <div class="profile-id-text">
                    <h1><?= e($target_name) ?></h1>
                    <div class="meta">
                        <?= e($me['email'] ?? '') ?>
                        <span class="role-pill"><?= e($me['role']) ?></span>
                    </div>
                </div>
            </div>

            <div class="profile-body">
                <?php if ($is_self): ?>
                    <!-- Self edit form: photo + cover + description in one save -->
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                        <input type="hidden" name="action" value="update_profile">

                        <!-- About / description -->
                        <div class="profile-section">
                            <h2>About you</h2>
                            <p class="helper">A line or two so people know who you are — under 1000 characters.</p>
                            <textarea name="description" class="bio-input" maxlength="1000"
                                      placeholder="Where you're from, what you study, what you're into…"><?= e($description) ?></textarea>
                        </div>

                        <!-- Profile photo -->
                        <div class="profile-section">
                            <h2>Your photo</h2>
                            <p class="helper">Square images look best. Anything under 5&nbsp;MB works — jpg, png, webp or gif.</p>
                            <div class="file-row">
                                <div class="file-preview <?= $photo_url ? 'has-img' : '' ?>"
                                     <?php if ($photo_url): ?>style="background-image: url('<?= e($photo_url) ?>');"<?php endif; ?>></div>
                                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif">
                            </div>
                        </div>

                        <!-- Cover photo -->
                        <div class="profile-section">
                            <h2>Cover banner</h2>
                            <p class="helper">The wide image behind your photo. Landscape shots around 1600&times;400 look best.</p>
                            <div class="file-row">
                                <div class="file-preview cover <?= $cover_url ? 'has-img' : '' ?>"
                                     <?php if ($cover_url): ?>style="background-image: url('<?= e($cover_url) ?>');"<?php endif; ?>></div>
                                <input type="file" name="cover_photo" accept="image/jpeg,image/png,image/webp,image/gif">
                            </div>
                        </div>

                        <div class="profile-actions">
                            <button type="submit" class="btn-primary">Save changes</button>
                            <a href="change_password.php" class="btn-ghost">Change password</a>
                        </div>
                    </form>

                    <?php if ($photo_url || $cover_url): ?>
                    <div class="profile-actions" style="margin-top:0.6rem;">
                        <?php if ($photo_url): ?>
                        <form method="post" style="display:inline;"
                              data-confirm="Remove your photo? You'll go back to initials.">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="remove_photo">
                            <button type="submit" class="btn-ghost danger">Remove photo</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($cover_url): ?>
                        <form method="post" style="display:inline;"
                              data-confirm="Remove your cover banner?">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="action" value="remove_cover">
                            <button type="submit" class="btn-ghost danger">Remove cover</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- Staff read-only view + moderation actions -->
                    <div class="profile-section">
                        <h2>About</h2>
                        <?php if ($description !== ''): ?>
                            <div class="bio-block"><?= nl2br(e($description)) ?></div>
                        <?php else: ?>
                            <div class="bio-empty">Nothing written here yet.</div>
                        <?php endif; ?>
                    </div>

                    <div class="profile-section">
                        <h2>Account</h2>
                        <p class="helper" style="margin-bottom:0;">
                            Joined <?= date('d M Y', strtotime($me['created_at'])) ?>
                            &middot; Role: <strong><?= e(ucfirst($me['role'])) ?></strong>
                        </p>
                    </div>

                    <?php if ($photo_url || $cover_url): ?>
                    <div class="profile-section">
                        <h2>Moderation</h2>
                        <p class="helper">Remove uploaded media on this user's behalf.</p>
                        <div class="profile-actions" style="margin-top:0;">
                            <?php if ($photo_url): ?>
                            <form method="post" style="display:inline;"
                                  data-confirm="Remove this user's profile photo?">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="mod_remove_photo">
                                <button type="submit" class="btn-ghost danger">Remove photo</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($cover_url): ?>
                            <form method="post" style="display:inline;"
                                  data-confirm="Remove this user's cover image?">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                <input type="hidden" name="action" value="mod_remove_cover">
                                <button type="submit" class="btn-ghost danger">Remove cover</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?= render_footer() ?>
<script src="../js/script.js"></script>
</body>
</html>
