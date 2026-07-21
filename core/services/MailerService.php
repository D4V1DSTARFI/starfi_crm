<?php
/**
 * Servicio central para encolar y enviar correos electrónicos.
 */
require_once __DIR__ . '/../libs/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../libs/PHPMailer/SMTP.php';
require_once __DIR__ . '/../libs/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class MailerService {
    private $db_core;

    public function __construct($db_connection) {
        $this->db_core = $db_connection;
        // Auto-instalar tablas si no existen (útil para producción)
        $this->db_core->query("CREATE TABLE IF NOT EXISTS configuracion_correo (
            id INT AUTO_INCREMENT PRIMARY KEY,
            smtp_host VARCHAR(255),
            smtp_port INT DEFAULT 587,
            smtp_user VARCHAR(255),
            smtp_pass VARCHAR(255),
            smtp_secure VARCHAR(50) DEFAULT 'tls',
            remitente_nombre VARCHAR(255),
            remitente_email VARCHAR(255) DEFAULT NULL,
            activo TINYINT(1) DEFAULT 0
        )");

        $this->db_core->query("CREATE TABLE IF NOT EXISTS cola_correos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            destinatario_email VARCHAR(255) NOT NULL,
            destinatario_nombre VARCHAR(255) NOT NULL,
            asunto VARCHAR(255) NOT NULL,
            cuerpo_html TEXT NOT NULL,
            adjunto_ruta VARCHAR(500) DEFAULT NULL,
            estado ENUM('Pendiente', 'Enviado', 'Error') DEFAULT 'Pendiente',
            intentos INT DEFAULT 0,
            error_mensaje TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sent_at TIMESTAMP NULL DEFAULT NULL
        )");
    }

    /**
     * Encola un correo para ser enviado posteriormente.
     */
    public function queueEmail($destinatario_email, $destinatario_nombre, $asunto, $cuerpo_html, $adjunto_ruta = null) {
        $stmt = $this->db_core->prepare("INSERT INTO cola_correos (destinatario_email, destinatario_nombre, asunto, cuerpo_html, adjunto_ruta) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $destinatario_email, $destinatario_nombre, $asunto, $cuerpo_html, $adjunto_ruta);
        return $stmt->execute();
    }

    /**
     * Procesa la cola de correos pendientes.
     * @param int $limite Número máximo de correos a enviar por lote
     * @return array Resumen de procesados (exitosos, errores)
     */
    public function processQueue($limite = 20) {
        // Cargar configuración activa
        $resConfig = $this->db_core->query("SELECT * FROM configuracion_correo WHERE activo = 1 LIMIT 1");
        if ($resConfig->num_rows == 0) {
            return ['status' => 'error', 'message' => 'No hay configuración de SMTP activa.'];
        }
        $config = $resConfig->fetch_assoc();

        $resCola = $this->db_core->query("SELECT * FROM cola_correos WHERE estado = 'Pendiente' OR (estado = 'Error' AND intentos < 3) ORDER BY created_at ASC LIMIT $limite");
        
        $exitosos = 0;
        $errores = 0;

        while ($correo = $resCola->fetch_assoc()) {
            $mail = new PHPMailer(true);
            try {
                // Configuración del servidor
                $mail->isSMTP();
                $mail->Host       = $config['smtp_host'];
                $mail->SMTPAuth   = true;
                $mail->Username   = $config['smtp_user'];
                $mail->Password   = $config['smtp_pass'];
                $mail->SMTPSecure = $config['smtp_secure'];
                $mail->Port       = $config['smtp_port'];
                $mail->CharSet    = 'UTF-8';

                // Remitente y destinatario
                $remitente_email = $config['remitente_email'] ?: $config['smtp_user'];
                $remitente_nombre = $config['remitente_nombre'] ?: 'STARFI 2.0';
                $mail->setFrom($remitente_email, $remitente_nombre);
                $mail->addAddress($correo['destinatario_email'], $correo['destinatario_nombre']);

                // Contenido
                $mail->isHTML(true);
                $mail->Subject = $correo['asunto'];
                $mail->Body    = $correo['cuerpo_html'];
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $correo['cuerpo_html']));

                // Archivo adjunto (soporta múltiples separados por coma)
                if (!empty($correo['adjunto_ruta'])) {
                    $rutas_adjuntos = explode(',', $correo['adjunto_ruta']);
                    foreach ($rutas_adjuntos as $ruta) {
                        $ruta_limpia = trim($ruta);
                        $ruta_real = realpath($ruta_limpia);
                        if ($ruta_real && file_exists($ruta_real)) {
                            $nombre_archivo = basename($ruta_real);
                            $mail->addAttachment($ruta_real, $nombre_archivo);
                        }
                    }
                }

                $mail->send();

                // Marcar como enviado
                $stmt = $this->db_core->prepare("UPDATE cola_correos SET estado = 'Enviado', sent_at = NOW(), error_mensaje = NULL WHERE id = ?");
                $stmt->bind_param("i", $correo['id']);
                $stmt->execute();
                $exitosos++;

            } catch (Exception $e) {
                // Marcar como error
                $error_msg = $mail->ErrorInfo;
                $stmt = $this->db_core->prepare("UPDATE cola_correos SET estado = 'Error', intentos = intentos + 1, error_mensaje = ? WHERE id = ?");
                $stmt->bind_param("si", $error_msg, $correo['id']);
                $stmt->execute();
                $errores++;
            }
        }

        return [
            'status' => 'success',
            'exitosos' => $exitosos,
            'errores' => $errores
        ];
    }

    /**
     * Envía un correo directamente sin pasar por la cola.
     * @return array [ 'status' => 'success|error', 'message' => '...' ]
     */
    public function sendDirectEmail($destinatario_email, $destinatario_nombre, $asunto, $cuerpo_html, $adjunto_ruta = null) {
        $resConfig = $this->db_core->query("SELECT * FROM configuracion_correo WHERE activo = 1 LIMIT 1");
        if ($resConfig->num_rows == 0) {
            return ['status' => 'error', 'message' => 'No hay configuración de SMTP activa.'];
        }
        $config = $resConfig->fetch_assoc();

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = $config['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['smtp_user'];
            $mail->Password   = $config['smtp_pass'];
            $mail->SMTPSecure = $config['smtp_secure'];
            $mail->Port       = $config['smtp_port'];
            $mail->CharSet    = 'UTF-8';

            $remitente_email = $config['remitente_email'] ?: $config['smtp_user'];
            $remitente_nombre = $config['remitente_nombre'] ?: 'STARFI 2.0';
            $mail->setFrom($remitente_email, $remitente_nombre);
            $mail->addAddress($destinatario_email, $destinatario_nombre);

            $mail->isHTML(true);
            $mail->Subject = $asunto;
            $mail->Body    = $cuerpo_html;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $cuerpo_html));

            if (!empty($adjunto_ruta)) {
                $rutas_adjuntos = explode(',', $adjunto_ruta);
                foreach ($rutas_adjuntos as $ruta) {
                    $ruta_limpia = trim($ruta);
                    $ruta_real = realpath($ruta_limpia);
                    if ($ruta_real && file_exists($ruta_real)) {
                        $nombre_archivo = basename($ruta_real);
                        $mail->addAttachment($ruta_real, $nombre_archivo);
                    }
                }
            }

            $mail->send();
            
            // Log to cola_correos
            $stmt = $this->db_core->prepare("INSERT INTO cola_correos (destinatario_email, destinatario_nombre, asunto, cuerpo_html, adjunto_ruta, estado, sent_at) VALUES (?, ?, ?, ?, ?, 'Enviado', NOW())");
            $stmt->bind_param("sssss", $destinatario_email, $destinatario_nombre, $asunto, $cuerpo_html, $adjunto_ruta);
            $stmt->execute();
            
            return ['status' => 'success', 'message' => 'Correo enviado correctamente.'];

        } catch (Exception $e) {
            $error_msg = $mail->ErrorInfo;
            $stmt = $this->db_core->prepare("INSERT INTO cola_correos (destinatario_email, destinatario_nombre, asunto, cuerpo_html, adjunto_ruta, estado, error_mensaje) VALUES (?, ?, ?, ?, ?, 'Error', ?)");
            $stmt->bind_param("ssssss", $destinatario_email, $destinatario_nombre, $asunto, $cuerpo_html, $adjunto_ruta, $error_msg);
            $stmt->execute();
            
            return ['status' => 'error', 'message' => 'Error al enviar correo: ' . $error_msg];
        }
    }
}
