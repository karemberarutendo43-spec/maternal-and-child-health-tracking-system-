<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/cards.php";
require_once __DIR__ . "/../config/epi.php";
require_once __DIR__ . "/../config/auth.php";
mch_session_start();

try {
    $pdo->beginTransaction();

    // 1️⃣ Collect BABY data (NOT mother data)
    $mother_id    = $_POST['mother_id']; // comes from dropdown / hidden input
    $baby_name    = $_POST['baby_name'];
    $dob          = $_POST['dob'];
    $gender       = $_POST['gender'];
    $birth_weight = $_POST['birth_weight'];

    // 2️⃣ Ensure mother exists
    $check = $pdo->prepare("SELECT id FROM mothers WHERE id = ?");
    $check->execute([$mother_id]);

    if ($check->rowCount() === 0) {
        throw new Exception("Mother not found. Register mother first.");
    }

    // 3️⃣ Save baby
    $card_uid = generate_card_uid();
    $stmt = $pdo->prepare("
        INSERT INTO babies 
        (mother_id, baby_name, dob, gender, birth_weight, card_uid)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    try {
        $stmt->execute([
            $mother_id,
            $baby_name,
            $dob,
            $gender,
            $birth_weight,
            $card_uid
        ]);
    } catch (PDOException $e) {
        if (!babies_supports_card_uid($pdo) || strpos($e->getMessage(), 'card_uid') !== false) {
            $stmt = $pdo->prepare("
                INSERT INTO babies
                (mother_id, baby_name, dob, gender, birth_weight)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $mother_id,
                $baby_name,
                $dob,
                $gender,
                $birth_weight
            ]);
        } else {
            throw $e;
        }
    }

    $baby_id = $pdo->lastInsertId();

    // 4️⃣ Auto-create immunization schedule
    mch_epi_insert_schedule($pdo, (int)$baby_id, (string)$dob);

    $pdo->commit();

    header("Location: ../babies/list.php?success=1");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Error saving baby: " . $e->getMessage());
}
