// Live VPN Dashboard Update Script
// Add this to your VPN dashboard to show real-time connections

class LiveVpnDashboard {
    constructor() {
        this.apiUrl = '/api/live-vpn-status.php';
        this.updateInterval = 5000; // 5 seconds
        this.isRunning = false;
    }

    start() {
        if (this.isRunning) return;
        this.isRunning = true;
        console.log('🔄 Starting live VPN dashboard updates...');
        this.update();
        this.timer = setInterval(() => this.update(), this.updateInterval);
    }

    stop() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
        this.isRunning = false;
        console.log('⏹️ Stopped live VPN dashboard updates');
    }

    async update() {
        try {
            const response = await fetch(this.apiUrl);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            
            if (data.success) {
                this.updateTotals(data.totals);
                this.updateConnectionsList(data.servers);
                this.updateTimestamp(data.timestamp);
                console.log(`📊 Updated: ${data.totals.active_connections} connections, ${data.totals.online_users} users`);
            } else {
                console.error('❌ API returned error:', data);
            }
        } catch (error) {
            console.error('❌ Failed to fetch VPN status:', error);
        }
    }

    updateTotals(totals) {
        // Update the dashboard totals
        this.updateElement('#total-connections', totals.active_connections);
        this.updateElement('#total-users', totals.online_users);
        this.updateElement('#active-servers', totals.active_servers);
    }

    updateConnectionsList(servers) {
        const container = document.getElementById('connections-list');
        if (!container) return;

        let html = '';
        
        servers.forEach(server => {
            if (server.active_connections.length > 0) {
                html += `
                    <div class="server-section">
                        <h4>🇩🇪 ${server.name} (${server.ip_address})</h4>
                        <div class="connections-table">
                `;
                
                server.active_connections.forEach(conn => {
                    html += `
                        <div class="connection-row">
                            <span class="username">👤 ${conn.username}</span>
                            <span class="protocol">${conn.protocol}</span>
                            <span class="ips">${conn.client_ip} → ${conn.virtual_ip}</span>
                            <span class="traffic">↓ ${conn.down_mb}MB ↑ ${conn.up_mb}MB</span>
                            <span class="connected">${conn.connected_since}</span>
                        </div>
                    `;
                });
                
                html += `
                        </div>
                    </div>
                `;
            }
        });

        if (html === '') {
            html = '<div class="no-connections">No active connections</div>';
        }

        container.innerHTML = html;
    }

    updateTimestamp(timestamp) {
        this.updateElement('#last-update', new Date(timestamp).toLocaleString());
    }

    updateElement(selector, value) {
        const element = document.querySelector(selector);
        if (element) {
            element.textContent = value;
        }
    }
}

// Auto-start when page loads
document.addEventListener('DOMContentLoaded', () => {
    window.vpnDashboard = new LiveVpnDashboard();
    window.vpnDashboard.start();
    
    console.log('🚀 Live VPN Dashboard initialized');
    console.log('💡 Use vpnDashboard.stop() to pause updates');
    console.log('💡 Use vpnDashboard.start() to resume updates');
});

// Add some basic CSS for the connections display
const style = document.createElement('style');
style.textContent = `
    .connection-row {
        display: flex;
        gap: 15px;
        padding: 8px;
        border-bottom: 1px solid #eee;
        font-family: monospace;
        font-size: 12px;
    }
    .username { min-width: 120px; font-weight: bold; }
    .protocol { min-width: 100px; color: #007bff; }
    .ips { min-width: 200px; color: #666; }
    .traffic { min-width: 150px; color: #28a745; }
    .connected { color: #6c757d; }
    .server-section { margin-bottom: 20px; }
    .no-connections { text-align: center; color: #999; padding: 20px; }
`;
document.head.appendChild(style);