<?php
require_once "../config/db.php";
require_once "../config/auth.php";
require_once "../immunizations/helper.php";
require_once "../config/cards.php";

$mother_id     = $_POST['mother_id'];
$delivery_date = $_POST['delivery_date'];
$delivery_type = $_POST['delivery_type'];
$outcome       = $_POST['outcome'];
$complications = $_POST['complications'] ?? null;
$user_id       = $_SESSION['user_id'];

$conn->begin_transaction();

try {
    // 1️⃣ Save delivery
    $stmt = $conn->prepare("
        INSERT INTO deliveries 
        (mother_id, delivery_date, delivery_type, outcome, complications, created_by)
        VALUES (?,?,?,?,?,?)
    ");
    $stmt->bind_param(
        "issssi",
        $mother_id,
        $delivery_date,
        $delivery_type,
        $outcome,
        $complications,
        $user_id
    );
    $stmt->execute();

    // 2️⃣ Update mother status
    $conn->query("
        UPDATE mothers 
        SET pregnancy_status = 'POSTNATAL'
        WHERE id = $mother_id
    ");

    // 3️⃣ Auto-create baby + immunizations
    if ($outcome === "LIVE") {

        $card_uid = generate_card_uid();
        $stmt = $conn->prepare("
            INSERT INTO babies (mother_id, baby_name, dob, card_uid)
            VALUES (?, ?, ?, ?)
        ");
        $baby_name = "Baby of Mother #$mother_id";
        $stmt->bind_param("isss", $mother_id, $baby_name, $delivery_date, $card_uid);
        $stmt->execute();

        $baby_id = $conn->insert_id;

        generateImmunizations($conn, $baby_id, $delivery_date);
    }

    $conn->commit();
    header("Location: add.php?success=1");

} catch (Exception $e) {
    $conn->rollback();
    die("Delivery failed: " . $e->getMessage());
}
