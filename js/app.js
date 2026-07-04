document.addEventListener('DOMContentLoaded', () => {
    
    // --- Sidebar Toggle ---
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggleSidebar');

    toggleBtn.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
    });

    // --- Tabs Switching ---
    const tabs = document.querySelectorAll('.tab');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            // Remove active class from all tabs
            tabs.forEach(t => t.classList.remove('active'));
            // Add active class to clicked tab
            tab.classList.add('active');
            
            // In a real app, this would filter the #chatList content based on tab.dataset.target
        });
    });

    // --- Chat Selection ---
    const chatItems = document.querySelectorAll('.chat-item');
    
    chatItems.forEach(item => {
        item.addEventListener('click', () => {
            chatItems.forEach(i => i.classList.remove('active'));
            item.classList.add('active');
            // Here you would load the conversation history for the selected chat
        });
    });

    // --- Profile Panel Toggle ---
    const profileBtn = document.querySelector('.icon-btn[title="Ver Perfil 360"]');
    const profilePanel = document.querySelector('.profile-preview');
    const closeProfileBtn = document.querySelector('.close-profile');

    profileBtn.addEventListener('click', () => {
        profilePanel.classList.toggle('open');
    });

    closeProfileBtn.addEventListener('click', () => {
        profilePanel.classList.remove('open');
    });

    // --- Sending Messages (Simulation) ---
    const chatInput = document.getElementById('chatInput');
    const sendBtn = document.getElementById('sendBtn');
    const messagesArea = document.getElementById('messagesArea');

    function sendMessage() {
        const text = chatInput.value.trim();
        if (text === '') return;

        // Create new message element (Agent side)
        const msgDiv = document.createElement('div');
        msgDiv.className = 'message agent-message';
        
        const now = new Date();
        const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        msgDiv.innerHTML = `
            <div class="msg-bubble">
                <p>${text}</p>
                <span class="msg-time">${timeStr} <i class="fa-solid fa-check"></i></span>
            </div>
        `;

        messagesArea.appendChild(msgDiv);
        chatInput.value = '';
        
        // Scroll to bottom
        messagesArea.scrollTop = messagesArea.scrollHeight;

        // Simulate read receipt update after 2 seconds
        setTimeout(() => {
            const checkIcon = msgDiv.querySelector('.fa-check');
            if (checkIcon) {
                checkIcon.classList.remove('fa-check');
                checkIcon.classList.add('fa-check-double');
                checkIcon.style.color = '#3B82F6'; // Blue color for read
            }
        }, 2000);
    }

    sendBtn.addEventListener('click', sendMessage);
    
    chatInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // --- Toast Notifications System ---
    function showToast(title, message, iconClass = 'fa-solid fa-bell') {
        const container = document.getElementById('toastContainer');
        
        const toast = document.createElement('div');
        toast.className = 'toast';
        
        toast.innerHTML = `
            <i class="${iconClass}"></i>
            <div class="toast-content">
                <h4>${title}</h4>
                <p>${message}</p>
            </div>
        `;
        
        container.appendChild(toast);

        // Remove toast after 5 seconds
        setTimeout(() => {
            toast.style.animation = 'fadeOut 0.3s ease-out forwards';
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 5000);
    }

    // Simulate an incoming webhook event after 5 seconds
    setTimeout(() => {
        showToast('Webhook Recibido', 'Pago confirmado para Empresa Corp S.A.', 'fa-solid fa-bolt');
    }, 5000);

});
