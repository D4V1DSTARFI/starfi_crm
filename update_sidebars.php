<?php
$modules = ['directorio/directorio.php', 'gestor_bots/gestor_bots.php', 'configuracion/configuracion.php', 'dashboard/dashboard.php'];
$replacement = <<<HTML
        <div class="sidebar-footer">
            <div class="agent-profile" style="display: flex; align-items: center; width: 100%;">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode(\$nombre_agente) ?>&background=EBF4FF&color=1E3A8A" alt="Avatar">
                <div class="agent-info" style="flex-grow: 1;">
                    <span class="agent-name" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100px; display: inline-block;"><?= htmlspecialchars(\$nombre_agente) ?></span>
                    <span class="agent-status online">En línea</span>
                </div>
                <a href="/starfi_crm/logout.php" class="btn text-danger p-1 m-0" title="Cerrar Sesión" style="font-size: 1.1rem;">
                    <i class="fa-solid fa-power-off"></i>
                </a>
            </div>
        </div>
    </aside>
HTML;

foreach ($modules as $mod) {
    $file = __DIR__ . "/modules/" . $mod;
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        // Add $agente = getAgenteInfo(); if not present
        if (strpos($content, '$agente = getAgenteInfo();') === false) {
            $content = str_replace(
                "requireAuth();\n?>",
                "requireAuth();\n\$agente = getAgenteInfo();\n\$nombre_agente = \$agente['nombre_completo'] ?? 'Usuario';\n?>",
                $content
            );
        }

        // Replace footer
        $content = preg_replace(
            '/<div class="sidebar-footer">.*?<\/aside>/s',
            $replacement,
            $content
        );
        file_put_contents($file, $content);
        echo "Updated $mod\n";
    }
}
?>
