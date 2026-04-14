<?php
header("Content-Type: application/json");

// Email de destination
define("NOTIFY_EMAIL", "buse974@gmail.com");

// Réponse JSON
function jsonResponse($success, $message, $code = 200)
{
    http_response_code($code);
    echo json_encode(["success" => $success, "message" => $message]);
    exit();
}

// Vérification méthode POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    jsonResponse(false, "Méthode non autorisée", 405);
}

// Récupération et nettoyage des données
$name = trim(htmlspecialchars($_POST["name"] ?? ""));
$email = trim(filter_var($_POST["email"] ?? "", FILTER_SANITIZE_EMAIL));
$projectType = trim(htmlspecialchars($_POST["project-type"] ?? ""));
$message = trim(htmlspecialchars($_POST["message"] ?? ""));

// Validation
if (empty($name) || empty($email) || empty($message)) {
    jsonResponse(false, "Veuillez remplir tous les champs obligatoires", 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, "Email invalide", 400);
}

// Préparation email
$subject =
    "=?UTF-8?B?" . base64_encode("Nouveau contact Altstud.IO - $name") . "?=";

$body = "Nouveau message depuis altstud.io\r\n";
$body .= "================================\r\n\r\n";
$body .= "Nom: $name\r\n";
$body .= "Email: $email\r\n";
$body .= "Type de projet: $projectType\r\n\r\n";
$body .= "Message:\r\n";
$body .= "--------\r\n";
$body .= "$message\r\n";

// Envoi via SMTP (postfix sur le même réseau Docker)
$from = "noreply@altstud.io";

$smtp = @fsockopen('postfix', 25, $errno, $errstr, 5);
if (!$smtp) {
    jsonResponse(false, "Erreur connexion SMTP", 500);
}

function smtpRead($smtp) {
    $response = "";
    while ($line = fgets($smtp, 515)) {
        $response .= $line;
        if (substr($line, 3, 1) === " ") break;
    }
    return $response;
}

function smtpSend($smtp, $cmd) {
    fwrite($smtp, $cmd . "\r\n");
    return smtpRead($smtp);
}

$ok = true;
smtpRead($smtp); // greeting
$ok = $ok && str_starts_with(smtpSend($smtp, "EHLO altstud.io"), "250");
$ok = $ok && str_starts_with(smtpSend($smtp, "MAIL FROM:<$from>"), "250");
$ok = $ok && str_starts_with(smtpSend($smtp, "RCPT TO:<" . NOTIFY_EMAIL . ">"), "250");
$ok = $ok && str_starts_with(smtpSend($smtp, "DATA"), "354");

$messageId = sprintf('<%s.%s@altstud.io>', bin2hex(random_bytes(8)), time());

$data = "Message-ID: $messageId\r\n";
$data .= "Date: " . date(DATE_RFC2822) . "\r\n";
$data .= "From: Altstud.IO <$from>\r\n";
$data .= "Reply-To: $name <$email>\r\n";
$data .= "To: " . NOTIFY_EMAIL . "\r\n";
$data .= "Subject: $subject\r\n";
$data .= "MIME-Version: 1.0\r\n";
$data .= "Content-Type: text/plain; charset=UTF-8\r\n";
$data .= "\r\n";
$data .= $body;

$ok = $ok && str_starts_with(smtpSend($smtp, "$data\r\n."), "250");
smtpSend($smtp, "QUIT");
fclose($smtp);

if ($ok) {
    jsonResponse(true, "Message envoyé avec succès !");
} else {
    jsonResponse(false, 'Erreur lors de l\'envoi', 500);
}
