<?php
/**
 * Admin POST action handlers
 * Extracted from admin.php for modularity
 */
// Called from admin.php - $db already available

function handle_admin_action($db) {
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'update_booking_status':
                $id = (int)($_POST['id'] ?? 0);
                $status = $_POST['status'] ?? '';
                $stmt = $db->prepare("UPDATE bookings SET status = ?, updated_at = datetime('now') WHERE id = ?");
                $stmt->execute([$status, $id]);
                echo json_encode(['ok'=>true]);
                break;
        case 'update_hotel':
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare("UPDATE hotels SET name=?, description=?, address=?, phone=?, cover_image=? WHERE id=?");
                $stmt->execute([$_POST['name']??'', $_POST['description']??'', $_POST['address']??'', $_POST['phone']??'', $_POST['cover_image']??'', $id]);
                echo json_encode(['ok'=>true]);
                break;
        case 'update_room':
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $db->prepare("UPDATE rooms SET name=?, price=?, quantity=?, is_active=? WHERE id=?");
                $stmt->execute([$_POST['name']??'', (float)$_POST['price'], (int)$_POST['quantity'], (int)$_POST['is_active'], $id]);
                echo json_encode(['ok'=>true]);
                break;
        case 'save_guide':
                $id = (int)($_POST['id'] ?? 0);
                $title = $_POST['title'] ?? '';
                $category = $_POST['category'] ?? '';
                $summary = $_POST['summary'] ?? '';
                $content = $_POST['content'] ?? '';
                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE guides SET title=?, category=?, summary=?, content=? WHERE id=?");
                    $stmt->execute([$title, $category, $summary, $content, $id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO guides (title, slug, category, summary, content, is_published, created_at) VALUES (?,?,?,?,?,1,datetime('now'))");
                    $slug = preg_replace('/[^a-zA-Z0-9\-]/', '', str_replace(' ', '-', mb_strtolower($title, 'UTF-8'))); $stmt->execute([$title, $slug, $category, $summary, $content]);
                }
                echo json_encode(['ok'=>true]);
                break;
        case 'delete_guide':
                $stmt = $db->prepare("DELETE FROM guides WHERE id=?");
                $stmt->execute([(int)$_POST['id']]);
                echo json_encode(['ok'=>true]);
                break;
        case 'add_hotel':
                $stmt=$db->prepare("INSERT INTO hotels (name,slug,star_rating,description,address,phone,cover_image,score,is_active) VALUES (?,?,?,?,?,?,?,4.5,1)");
                $slug=preg_replace('/[^a-zA-Z0-9\-]/','',str_replace(' ','-',mb_strtolower($_POST['name']??'', 'UTF-8')));
                if(!$slug)$slug='hotel'.time();
                $stmt->execute([$_POST['name']??'', $slug, (int)($_POST['star_rating']??3), $_POST['description']??'', $_POST['address']??'', $_POST['phone']??'', $_POST['cover_image']??'']);
                echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]);
                break;
        case 'add_room':
                $stmt=$db->prepare("INSERT INTO rooms (hotel_id,name,bed_type,price,quantity,is_active,sort_order) VALUES (?,?,?,?,?,1,0)");
                $stmt->execute([(int)$_POST['hotel_id'], $_POST['name']??'', $_POST['bed_type']??'', (float)$_POST['price'], (int)$_POST['quantity']]);
                echo json_encode(['ok'=>true,'id'=>(int)$db->lastInsertId()]);
                break;
        default:
            echo json_encode(['ok'=>false,'error'=>'未知操作']);
            exit;
    }
}
