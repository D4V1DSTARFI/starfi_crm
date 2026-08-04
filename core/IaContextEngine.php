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
        // MÓDULO DE INVENTARIO PAUSADO TEMPORALMENTE HASTA NUEVO AVISO POR EL ADMINISTRADOR
        return "[SISTEMA: La consulta automática de inventario y catálogo está temporalmente PAUSADA. Si el cliente consulta sobre productos o existencias, infórmale con amabilidad que un asesor comercial le atenderá personalmente para compartirle precios y catálogo. Si solicita la dirección o ubicación, proporciónala. Si solicita ser asignado a un operador, transfiérelo amablemente.]";
    }
}
