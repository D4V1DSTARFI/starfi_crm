let editor = null;
let currentViewMode = 'table'; // 'table' or 'visual'

function toggleViewMode() {
    if (currentViewMode === 'table') {
        $('#tableContainer').hide();
        $('#paginationContainer').hide();
        $('#drawflowContainer').fadeIn();
        $('#btnToggleView').html('<i class="fa-solid fa-list me-1"></i> Modo Lista');
        currentViewMode = 'visual';
        initDrawflow();
    } else {
        $('#drawflowContainer').hide();
        $('#tableContainer').fadeIn();
        $('#paginationContainer').fadeIn();
        $('#btnToggleView').html('<i class="fa-solid fa-project-diagram me-1"></i> Modo Visual');
        currentViewMode = 'table';
    }
}

function initDrawflow() {
    if (editor !== null) {
        editor.clearModuleSelected();
        renderNodesFromRules();
        return;
    }

    const id = document.getElementById("drawflow");
    editor = new Drawflow(id);
    editor.reroute = true;
    editor.reroute_fix_curvature = true;
    editor.start();

    // Editor events
    editor.on('nodeCreated', function(id) {
        // console.log("Node created " + id);
    });

    editor.on('nodeSelected', function(id) {
        // Could open modal on select, but double click is better. We will bind dblclick to the node element.
    });

    renderNodesFromRules();
}

function renderNodesFromRules() {
    if(!editor) return;
    editor.clearModuleSelected();
    
    // 1. Create Nodes
    // Drawflow uses internal IDs (1, 2, 3). We need to map our DB 'id' to Drawflow 'id'.
    let dbIdToDfId = {};
    
    // We will place nodes in a grid to avoid overlapping.
    let x = 100;
    let y = 100;
    
    allRules.forEach((rule, index) => {
        let icon = '<i class="fa-solid fa-comment text-primary"></i>';
        if (rule.formato_respuesta === 'IMAGEN') icon = '<i class="fa-solid fa-image text-success"></i>';
        if (rule.formato_respuesta === 'UBICACION') icon = '<i class="fa-solid fa-map-marker-alt text-danger"></i>';
        if (rule.formato_respuesta === 'CONTACTOS_SEDE' || rule.formato_respuesta === 'CONTACTOS') icon = '<i class="fa-solid fa-address-book text-info"></i>';
        if (rule.tipo === 'CIERRE_CSAT') icon = '<i class="fa-solid fa-star text-warning"></i>';
        
        let headerColor = rule.espera_respuesta == 1 ? '#FEF3C7' : '#EFF6FF';
        let headerBorder = rule.espera_respuesta == 1 ? '#FDE68A' : '#BFDBFE';
        
        let html = `
            <div ondblclick="editBotRule(${rule.id})">
                <div class="title-box" style="background: ${headerColor}; border-bottom: 1px solid ${headerBorder};">
                    <span>${icon} ${rule.disparador}</span>
                    <span class="badge bg-dark">${rule.id}</span>
                </div>
                <div class="box">
                    <p style="margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.75rem;">${rule.mensaje}</p>
                </div>
            </div>
        `;
        
        // Output connection only if it's a menu (espera_respuesta = 1) OR just always 1 output, 1 input
        // Actually, if it's a global rule (id_padre = NULL) it has input 0? No, let's give every node 1 input and 1 output for flexibility.
        let inputs = 1;
        let outputs = 1;
        
        // x and y placement logic (very simple grid)
        let posX = x + (index % 4) * 300;
        let posY = y + Math.floor(index / 4) * 150;
        
        // To make it look like a tree, we could do topological sort, but simple grid is fine for drag & drop.
        let dfId = editor.addNode('rule_'+rule.id, inputs, outputs, posX, posY, 'rule-node', data = { db_id: rule.id }, html);
        dbIdToDfId[rule.id] = dfId;
    });

    // 2. Connect Nodes (id_padre -> id)
    allRules.forEach(rule => {
        if (rule.id_padre && dbIdToDfId[rule.id_padre]) {
            let parentDfId = dbIdToDfId[rule.id_padre];
            let childDfId = dbIdToDfId[rule.id];
            
            // Connect output 1 of parent to input 1 of child
            editor.addConnection(parentDfId, childDfId, 'output_1', 'input_1');
        }
    });
}

function saveDrawflowNetwork() {
    if(!editor) return;
    
    let exportData = editor.export();
    let nodes = exportData.drawflow.Home.data;
    
    let updates = [];
    
    // Iterate over all nodes to find their connections
    Object.keys(nodes).forEach(nodeKey => {
        let node = nodes[nodeKey];
        let db_id = node.data.db_id;
        
        // Find who is connected to this node's input
        // A node can only have ONE parent in our DB schema (id_padre is a single INT)
        let inputConnections = node.inputs.input_1.connections;
        let id_padre = null;
        
        if (inputConnections.length > 0) {
            // Get the first connection's parent DF ID
            let parentDfId = inputConnections[0].node;
            // Get the parent's DB ID
            let parentNode = nodes[parentDfId];
            if(parentNode) {
                id_padre = parentNode.data.db_id;
            }
        }
        
        updates.push({
            id: db_id,
            id_padre: id_padre
        });
    });
    
    if(updates.length === 0) {
        Swal.fire('Info', 'No hay nodos para guardar.', 'info');
        return;
    }
    
    // Save to backend
    $.ajax({
        url: 'back_gestor_bots.php',
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'update_network',
            nodes: JSON.stringify(updates)
        },
        success: function(response) {
            if (response.status === 'success') {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Árbol Guardado Correctamente',
                    showConfirmButton: false,
                    timer: 2000
                });
                loadBotRules(); // Reload global array from DB to reflect new parents
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        }
    });
}
