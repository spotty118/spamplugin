/**
 * SpamShield Analytics Dashboard JavaScript - NO Chart.js Version
 * Uses simple HTML/CSS charts to prevent any freezing or infinite spreading
 */

(function($) {
    'use strict';
    
    const SSCFDashboard = {
        
        currentPeriod: 30,
        updateInterval: null,
        isUpdating: false,
        initCount: 0,
        
        init: function() {
            this.initCount++;
            if (this.initCount > 1) {
                console.log('Dashboard already initialized, skipping duplicate init...');
                return;
            }
            
            console.log('Initializing NO Chart.js SpamShield Dashboard...');
            this.initializeTheme();
            this.bindEvents();
            this.createSimpleCharts();
            
            // Initial data load
            setTimeout(() => {
                this.updateCharts();
            }, 1000);
            
            // Start auto-updates
            setTimeout(() => {
                this.startAutoUpdates();
            }, 5000);
        },
        
        bindEvents: function() {
            // Unbind existing events first to prevent duplicates
            $('.sscf-chart-controls .button').off('click.dashboard');
            $(document).off('click.dashboard', '.sscf-refresh-data');
            $(document).off('click.dashboard', '#sscf-theme-toggle');
            
            // Chart period buttons
            $('.sscf-chart-controls .button').on('click.dashboard', this.changePeriod.bind(this));
            
            // Refresh data button
            $(document).on('click.dashboard', '.sscf-refresh-data', this.refreshAllData.bind(this));
            
            // Theme toggle button
            $(document).on('click.dashboard', '#sscf-theme-toggle', this.toggleTheme.bind(this));
        },
        
        changePeriod: function(e) {
            e.preventDefault();
            
            if (this.isUpdating) {
                console.log('Update in progress, ignoring period change');
                return;
            }
            
            const $button = $(e.target);
            const period = parseInt($button.data('period'));
            
            if (!period || period === this.currentPeriod) {
                return;
            }
            
            // Update button states
            $('.sscf-chart-controls .button').removeClass('button-primary').addClass('button-secondary');
            $button.removeClass('button-secondary').addClass('button-primary');
            
            this.currentPeriod = period;
            console.log('Period changed to:', period);
            this.updateCharts();
        },
        
        toggleTheme: function(e) {
            e.preventDefault();
            
            const $dashboard = $('.sscf-dashboard');
            const $button = $('#sscf-theme-toggle');
            
            // Toggle theme class
            if ($dashboard.hasClass('sscf-light-theme')) {
                // Switch to dark theme
                $dashboard.removeClass('sscf-light-theme').addClass('sscf-dark-theme');
                $button.find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden');
                localStorage.setItem('sscf_theme', 'dark');
                console.log('Switched to dark theme');
            } else {
                // Switch to light theme
                $dashboard.removeClass('sscf-dark-theme').addClass('sscf-light-theme');
                $button.find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
                localStorage.setItem('sscf_theme', 'light');
                console.log('Switched to light theme');
            }
            
            // Recreate charts with new theme colors
            this.createSimpleCharts();
            if (!this.isUpdating) {
                this.updateCharts();
            }
        },
        
        initializeTheme: function() {
            const savedTheme = localStorage.getItem('sscf_theme') || 'light';
            const $dashboard = $('.sscf-dashboard');
            const $button = $('#sscf-theme-toggle');
            
            if (savedTheme === 'dark') {
                $dashboard.addClass('sscf-dark-theme');
                $button.find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden');
            } else {
                $dashboard.addClass('sscf-light-theme');
                $button.find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
            }
            
            console.log('Theme initialized:', savedTheme);
        },
        
        getThemeColors: function() {
            const $dashboard = $('.sscf-dashboard');
            const isDarkTheme = $dashboard.hasClass('sscf-dark-theme');
            
            return {
                text: isDarkTheme ? '#f0f0f1' : '#23282d',
                muted: isDarkTheme ? '#c3c4c7' : '#666',
                background: isDarkTheme ? '#2c3338' : '#ffffff',
                border: isDarkTheme ? '#3c4043' : '#e1e1e1'
            };
        },
        
        createSimpleCharts: function() {
            this.createSimpleTimelineChart();
            this.createSimpleMethodsChart();
            this.createSimpleTypesChart();
            console.log('Simple HTML/CSS charts created');
        },
        
        createSimpleTimelineChart: function() {
            const container = $('#sscf-timeline-chart').parent();
            if (!container.length) return;
            
            // Get theme colors
            const colors = this.getThemeColors();
            
            container.html(`
                <div id="simple-timeline-chart" style="padding: 20px;">
                    <h3 style="color: ${colors.text}; margin: 0 0 20px 0;">Activity Timeline</h3>
                    <div id="timeline-bars" style="display: flex; align-items: end; height: 200px; gap: 5px; margin-top: 20px;">
                        <div style="text-align: center; color: ${colors.muted};">No data available yet</div>
                    </div>
                    <div style="display: flex; gap: 20px; margin-top: 15px;">
                        <div style="color: ${colors.text};"><span style="color: #dc3232;">■</span> Spam Blocked</div>
                        <div style="color: ${colors.text};"><span style="color: #46b450;">■</span> Clean Submissions</div>
                    </div>
                </div>
            `);
        },
        
        createSimpleMethodsChart: function() {
            const container = $('#sscf-methods-chart').parent();
            if (!container.length) return;
            
            // Get theme colors
            const colors = this.getThemeColors();
            
            container.html(`
                <div id="simple-methods-chart" style="padding: 20px;">
                    <h3 style="color: ${colors.text}; margin: 0 0 20px 0;">Detection Methods</h3>
                    <div id="methods-list" style="margin-top: 20px;">
                        <div style="text-align: center; color: ${colors.muted}; padding: 40px;">No spam detected yet</div>
                    </div>
                </div>
            `);
        },
        
        createSimpleTypesChart: function() {
            const container = $('#sscf-types-chart').parent();
            if (!container.length) return;
            
            // Get theme colors
            const colors = this.getThemeColors();
            
            container.html(`
                <div id="simple-types-chart" style="padding: 20px;">
                    <h3 style="color: ${colors.text}; margin: 0 0 20px 0;">Entry Types</h3>
                    <div id="types-list" style="margin-top: 20px;">
                        <div style="text-align: center; color: ${colors.muted}; padding: 40px;">No entries recorded yet</div>
                    </div>
                </div>
            `);
        },
        
        updateCharts: function() {
            if (this.isUpdating) {
                console.log('Update already in progress, skipping...');
                return Promise.resolve();
            }
            
            this.isUpdating = true;
            console.log('Starting simple chart update for period:', this.currentPeriod);
            
            return this.fetchAnalyticsData()
                .then(data => {
                    console.log('Received analytics data:', data);
                    
                    this.updateSimpleTimelineChart(data.timeline || {});
                    this.updateSimpleMethodsChart(data.methods || {});
                    this.updateSimpleTypesChart(data.types || {});
                    
                    this.isUpdating = false;
                    console.log('Simple chart update completed');
                })
                .catch(error => {
                    console.error('Chart update error:', error);
                    this.isUpdating = false;
                });
        },
        
        updateSimpleTimelineChart: function(timelineData) {
            const container = $('#timeline-bars');
            if (!container.length) return;
            
            const labels = Array.isArray(timelineData.labels) ? timelineData.labels : [];
            const spamData = Array.isArray(timelineData.spam) ? timelineData.spam : [];
            const cleanData = Array.isArray(timelineData.clean) ? timelineData.clean : [];
            
            if (labels.length === 0) {
                container.html('<div style="text-align: center; color: #666; height: 180px; line-height: 180px;">No data available yet</div>');
                return;
            }
            
            const maxValue = Math.max(...spamData, ...cleanData, 1);
            let html = '';
            
            for (let i = 0; i < labels.length; i++) {
                const spam = spamData[i] || 0;
                const clean = cleanData[i] || 0;
                const spamHeight = (spam / maxValue) * 150;
                const cleanHeight = (clean / maxValue) * 150;
                
                html += `
                    <div style="display: flex; flex-direction: column; align-items: center; min-width: 40px;">
                        <div style="display: flex; align-items: end; height: 150px;">
                            <div style="background: #dc3232; width: 15px; height: ${spamHeight}px; margin-right: 2px;" title="Spam: ${spam}"></div>
                            <div style="background: #46b450; width: 15px; height: ${cleanHeight}px;" title="Clean: ${clean}"></div>
                        </div>
                        <div style="font-size: 11px; margin-top: 5px; transform: rotate(-45deg); white-space: nowrap;">${labels[i]}</div>
                    </div>
                `;
            }
            
            container.html(html);
            console.log('Simple timeline chart updated with', labels.length, 'data points');
        },
        
        updateSimpleMethodsChart: function(methodsData) {
            const container = $('#methods-list');
            if (!container.length) return;
            
            const labels = Array.isArray(methodsData.labels) ? methodsData.labels : [];
            const values = Array.isArray(methodsData.values) ? methodsData.values : [];
            
            if (labels.length === 0) {
                container.html('<div style="text-align: center; color: #666; padding: 40px;">No spam detected yet</div>');
                return;
            }
            
            const total = values.reduce((a, b) => a + b, 0);
            let html = '';
            const colors = ['#dc3232', '#ffb900', '#00a0d2', '#46b450', '#826eb4', '#f56e28'];
            
            for (let i = 0; i < labels.length; i++) {
                const value = values[i] || 0;
                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                const color = colors[i % colors.length];
                
                html += `
                    <div style="display: flex; align-items: center; margin-bottom: 10px;">
                        <div style="width: 20px; height: 20px; background: ${color}; margin-right: 10px; border-radius: 3px;"></div>
                        <div style="flex: 1;">${labels[i]}: ${value} (${percentage}%)</div>
                        <div style="width: 100px; height: 8px; background: #f0f0f0; border-radius: 4px; margin-left: 10px;">
                            <div style="width: ${percentage}%; height: 100%; background: ${color}; border-radius: 4px;"></div>
                        </div>
                    </div>
                `;
            }
            
            container.html(html);
            console.log('Simple methods chart updated with', labels.length, 'categories');
        },
        
        updateSimpleTypesChart: function(typesData) {
            const container = $('#types-list');
            if (!container.length) return;
            
            const labels = Array.isArray(typesData.labels) ? typesData.labels : [];
            const values = Array.isArray(typesData.values) ? typesData.values : [];
            
            if (labels.length === 0) {
                container.html('<div style="text-align: center; color: #666; padding: 40px;">No entries recorded yet</div>');
                return;
            }
            
            const total = values.reduce((a, b) => a + b, 0);
            let html = '';
            const colors = ['#0073aa', '#00a0d2', '#826eb4', '#f56e28'];
            
            for (let i = 0; i < labels.length; i++) {
                const value = values[i] || 0;
                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                const color = colors[i % colors.length];
                
                html += `
                    <div style="display: flex; align-items: center; margin-bottom: 10px;">
                        <div style="width: 20px; height: 20px; background: ${color}; margin-right: 10px; border-radius: 50%;"></div>
                        <div style="flex: 1;">${labels[i]}: ${value} (${percentage}%)</div>
                        <div style="width: 100px; height: 8px; background: #f0f0f0; border-radius: 4px; margin-left: 10px;">
                            <div style="width: ${percentage}%; height: 100%; background: ${color}; border-radius: 4px;"></div>
                        </div>
                    </div>
                `;
            }
            
            container.html(html);
            console.log('Simple types chart updated with', labels.length, 'categories');
        },
        
        fetchAnalyticsData: function() {
            return new Promise((resolve, reject) => {
                console.log('Fetching analytics data via AJAX...');
                
                $.ajax({
                    url: sscf_dashboard.ajax_url,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'sscf_get_analytics_data',
                        nonce: sscf_dashboard.nonce,
                        period: this.currentPeriod
                    },
                    timeout: 15000,
                    success: function(response) {
                        console.log('AJAX success:', response);
                        if (response.success && response.data) {
                            resolve(response.data);
                        } else {
                            console.warn('AJAX success but no data, using empty structure');
                            resolve({
                                timeline: { labels: [], spam: [], clean: [] },
                                methods: { labels: [], values: [] },
                                types: { labels: [], values: [] }
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', status, error, xhr.responseText);
                        resolve({
                            timeline: { labels: [], spam: [], clean: [] },
                            methods: { labels: [], values: [] },
                            types: { labels: [], values: [] }
                        });
                    }
                });
            });
        },
        
        refreshAllData: function(e) {
            if (e) e.preventDefault();
            
            if (this.isUpdating) {
                console.log('Already updating, refresh ignored');
                return;
            }
            
            console.log('Manual refresh triggered');
            this.updateCharts();
            this.updateStats();
        },
        
        updateStats: function() {
            $.ajax({
                url: sscf_dashboard.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'sscf_get_stats',
                    nonce: sscf_dashboard.nonce
                },
                timeout: 10000,
                success: function(response) {
                    if (response.success && response.data) {
                        $('.sscf-stat-number').each(function() {
                            const $this = $(this);
                            const statType = $this.data('stat');
                            if (response.data[statType] !== undefined) {
                                $this.text(response.data[statType]);
                            }
                        });
                        console.log('Stats updated');
                    }
                },
                error: function(xhr, status, error) {
                    console.warn('Stats update error:', status, error);
                }
            });
        },
        
        startAutoUpdates: function() {
            // Clear any existing interval
            this.stopAutoUpdates();
            
            // Auto-refresh every 5 minutes
            this.updateInterval = setInterval(() => {
                if (!this.isUpdating) {
                    console.log('Auto-refresh triggered');
                    this.updateCharts();
                    this.updateStats();
                }
            }, 300000); // 5 minutes
            
            console.log('Auto-updates started (5 minute interval)');
        },
        
        stopAutoUpdates: function() {
            if (this.updateInterval) {
                clearInterval(this.updateInterval);
                this.updateInterval = null;
                console.log('Auto-updates stopped');
            }
        },
        
        cleanup: function() {
            console.log('Cleaning up dashboard...');
            this.stopAutoUpdates();
            $('.sscf-chart-controls .button').off('click.dashboard');
            $(document).off('click.dashboard', '.sscf-refresh-data');
        }
    };
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        if ($('#sscf-timeline-chart').length > 0 || $('.sscf-chart-container').length > 0) {
            SSCFDashboard.init();
        } else {
            console.log('Dashboard charts not found, skipping initialization');
        }
    });
    
    // Cleanup on page unload
    $(window).on('beforeunload', function() {
        if (typeof SSCFDashboard !== 'undefined') {
            SSCFDashboard.cleanup();
        }
    });
    
    // Make available globally for debugging
    window.SSCFDashboard = SSCFDashboard;
    
})(jQuery);
