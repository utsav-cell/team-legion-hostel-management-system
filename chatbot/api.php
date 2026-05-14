<?php
// ─────────────────────────────────────────────────
// api/chatbot.php — rule-based intent matching + conversation log + rating
// Endpoints (POST JSON or form):
//   action=message  message=<text>  session_id=<uuid>   → returns {reply, conversation_id, intent_id}
//   action=rate     conversation_id=<id>  rating=<1-5> → returns {success}
// ─────────────────────────────────────────────────

require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Accept JSON body too.
$body = $_POST;
if (empty($body) && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
}

try {
    csrf_verify($body['csrf_token'] ?? '');

    $action     = $body['action'] ?? 'message';
    $session_id = trim($body['session_id'] ?? '');
    if ($session_id === '' || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $session_id)) {
        throw new Exception('Invalid session.');
    }

    $auth = get_auth();
    $student_id = ($auth && $auth['role'] === 'student') ? (int)$auth['id'] : null;

    if ($action === 'message') {
        $text = trim($body['message'] ?? '');
        if ($text === '')              throw new Exception('Empty message.');
        if (mb_strlen($text) > 500)    throw new Exception('Message too long (max 500 chars).');

        $tokens = chatbot_tokenize($text);

        $intents = $pdo->query("SELECT id, keywords, response, action, priority FROM chatbot_intents WHERE is_active = 1")->fetchAll();

        $best = null;
        foreach ($intents as $intent) {
            $kw_list = array_filter(array_map('trim', explode(',', strtolower($intent['keywords']))));
            $score = 0;
            foreach ($kw_list as $kw) {
                if ($kw === '') continue;
                if (strpos($kw, ' ') !== false) {
                    if (strpos(' ' . implode(' ', $tokens) . ' ', ' ' . $kw . ' ') !== false) {
                        $score += 2;
                    }
                } else {
                    if (in_array($kw, $tokens, true)) $score++;
                }
            }
            if ($score === 0) continue;
            if ($best === null
                || $score > $best['score']
                || ($score === $best['score'] && (int)$intent['priority'] > $best['priority'])) {
                $best = [
                    'id'       => (int)$intent['id'],
                    'response' => $intent['response'],
                    'action'   => $intent['action'],
                    'score'    => $score,
                    'priority' => (int)$intent['priority'],
                ];
            }
        }

        $reply     = $best['response'] ?? null;
        $intent_id = $best['id']     ?? null;
        $chatbot_action = $best['action'] ?? null;

        // No intent matched - auto-forward to owner enquiries and reply warmly
        $forwarded = false;
        if ($reply === null) {
            // Get user details for the enquiry row
            $enq_name  = 'Chatbot Visitor';
            $enq_email = '';
            if ($student_id) {
                $stu = $pdo->prepare("SELECT name, email FROM users WHERE id = ? LIMIT 1");
                $stu->execute([$student_id]);
                $stu_row   = $stu->fetch();
                $enq_name  = $stu_row['name']  ?? 'Chatbot Visitor';
                $enq_email = $stu_row['email'] ?? '';
            }

            try {
                $pdo->prepare(
                    "INSERT INTO enquiries (name, email, message, source, status) VALUES (?, ?, ?, 'Chatbot', 'unread')"
                )->execute([$enq_name, $enq_email, $text]);
                $forwarded = true;
            } catch (Exception $e) {}

            if ($enq_email) {
                $reply = "I don't have an answer for that yet. I've forwarded your question to the HMS team and you'll receive a reply at {$enq_email} shortly.";
            } else {
                $reply = "I don't have an answer for that yet. I've forwarded your question to the HMS team. Log in with a registered account so they can email you back.";
            }
        }

        $ins = $pdo->prepare(
            "INSERT INTO chatbot_conversations (student_id, session_id, user_message, bot_response, matched_intent_id)
             VALUES (?, ?, ?, ?, ?)"
        );
        $ins->execute([$student_id, $session_id, $text, $reply, $intent_id]);

        echo json_encode([
            'success'         => true,
            'reply'           => $reply,
            'action'          => $chatbot_action,
            'forwarded'       => $forwarded,
            'conversation_id' => (int)$pdo->lastInsertId(),
            'intent_id'       => $intent_id,
            'matched'         => $intent_id !== null,
            'student'         => $student_id ? [
                'id'    => $student_id,
                'name'  => $auth['name']  ?? null,
                'email' => null,
            ] : null,
        ]);
        exit;
    }

    if ($action === 'rate') {
        $conv_id = (int)($body['conversation_id'] ?? 0);
        $rating  = (int)($body['rating'] ?? 0);
        if ($conv_id < 1)                  throw new Exception('Invalid conversation.');
        if ($rating < 1 || $rating > 5)    throw new Exception('Rating must be 1-5.');

        // Only allow rating rows from this session
        $upd = $pdo->prepare("UPDATE chatbot_conversations SET rating = ? WHERE id = ? AND session_id = ?");
        $upd->execute([$rating, $conv_id, $session_id]);
        echo json_encode(['success' => true, 'updated' => $upd->rowCount()]);
        exit;
    }

    throw new Exception('Unknown action.');
} catch (Throwable $ex) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
}

function chatbot_tokenize(string $s): array {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    $s = preg_replace('/\s+/', ' ', trim($s));
    if ($s === '') return [];
    return explode(' ', $s);
}
