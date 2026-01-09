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

// Headers améliorés pour éviter les spams
$headers = [];
$headers[] = "MIME-Version: 1.0";
$headers[] = "Content-Type: text/plain; charset=UTF-8";
$headers[] = "From: Altstud.IO <noreply@altstud.io>";
$headers[] = "Reply-To: $name <$email>";
$headers[] = "Return-Path: noreply@altstud.io";
$headers[] = "X-Mailer: Altstud.IO Contact Form";
$headers[] = "X-Priority: 1";

if (mail(NOTIFY_EMAIL, $subject, $body, implode("\r\n", $headers))) {
    jsonResponse(true, "Message envoyé avec succès !");
} else {
    jsonResponse(false, 'Erreur lors de l\'envoi', 500);
}
