<?php
/**
 * Motor de Inyección de Contexto en Tiempo Real (Just-In-Time RAG)
 * Asistente Virtual Multi-Sede - STARFI CRM
 * 
 * Garantiza el aislamiento estricto de inventario por id_sede.
 */

class IaContextEngine {
    
    /**
     * Extrae palabras clave significativas del mensaje del cliente.
     */
    public static function extraerPalabrasClave(string $mensaje): array {
        // Convertir a minúsculas y limpiar caracteres especiales
        $mensajeLimpio = mb_strtolower(trim($mensaje), 'UTF-8');
        $mensajeLimpio = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $mensajeLimpio);
        
        // Palabras vacías a ignorar
        $stopWords = [
            'hola', 'buen', 'dia', 'dias', 'tardes', 'noches', 'favor', 'gracias',
            'tienen', 'precio', 'costo', 'cuanto', 'cuesta', 'valor', 'disp',
            'disponible', 'disponibilidad', 'stock', 'existencia', 'busco', 'necesito',
            'quisiera', 'saber', 'sobre', 'para', 'como', 'esta', 'este', 'esta',
            'donde', 'están', 'estamos', 'venden', 'tendrás', 'tendras', 'quiero'
        ];
        
        $tokens = preg_split('/\s+/u', $mensajeLimpio);
        $keywords = [];
        
        foreach ($tokens as $token) {
            $token = trim($token);
            if (mb_strlen($token, 'UTF-8') >= 3 && !in_array($token, $stopWords)) {
                $keywords[] = $token;
            }
        }
        
        return array_unique($keywords);
    }
    
    /**
     * Consulta el inventario en MySQL acotado estrictamente por id_sede.
     */
    public static function obtenerContextoInventario(mysqli $con, int $idSede, string $mensajeCliente): string {
        if ($idSede <= 0 || empty(trim($mensajeCliente))) {
            return "";
        }
        
        $keywords = self::extraerPalabrasClave($mensajeCliente);
        if (empty($keywords)) {
            return "";
        }
        
        // Armar condiciones LIKE preparadas de forma segura
        $likeConditions = [];
        foreach ($keywords as $kw) {
            $kwEscaped = mysqli_real_escape_string($con, $kw);
            $likeConditions[] = "(nombre_producto LIKE '%$kwEscaped%' OR codigo LIKE '%$kwEscaped%')";
        }
        
        $whereKeywords = "(" . implode(" OR ", $likeConditions) . ")";
        
        // Consulta ESTRICTA acotada por id_sede
        $sql = "SELECT codigo, nombre_producto, precio, stock, garantia 
                FROM inventario_productos 
                WHERE id_sede = $idSede AND stock > 0 AND $whereKeywords 
                ORDER BY stock DESC 
                LIMIT 5";
        
        $res = mysqli_query($con, $sql);
        
        if (!$res || mysqli_num_rows($res) === 0) {
            return "[SISTEMA: No se encontraron productos coincidentes en el inventario de la Sede #$idSede.]";
        }
        
        $contextoText = "[DATOS EN TIEMPO REAL - INVENTARIO SEDE #$idSede]:\n";
        while ($p = mysqli_fetch_assoc($res)) {
            $garantiaText = !empty($p['garantia']) ? " | Garantía: {$p['garantia']}" : "";
            $codigoText = !empty($p['codigo']) ? " [Cód: {$p['codigo']}]" : "";
            $contextoText .= "- {$p['nombre_producto']}$codigoText | Precio: $" . number_format($p['precio'], 2) . " | Stock disponible: {$p['stock']} un$garantiaText\n";
        }
        
        return $contextoText;
    }
}
