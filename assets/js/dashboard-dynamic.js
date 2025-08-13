/**
 * SpamShield Analytics Dashboard JavaScript - Fixed Version
 * Prevents infinite chart spreading and ensures proper data handling
 */

(function($) {
    'use strict';
    
    // Set Chart.js global performance defaults BEFORE any charts are created
    if (typeof Chart !== 'undefined') {
        Chart.defaults.animation = false;
        Chart.defaults.responsive = true;
        Chart.defaults.maintainAspectRatio = false;
        Chart.defaults.interaction.intersect = false;
    }
    
    const SSCFDashboard = {
        
        charts: {},
        currentPeriod: 30,
        updateInterval: null,
        isUpdating: false,
        maxDataPoints: 30, // Maximum number of data points to display
        
        init: function() {
            console.log('Initializing SpamShield Dashboard...');
            this.bindEvents();
            this.createEmptyCharts();
            this.updateCharts();
            // Delay auto-updates to let initial load complete
            setTimeout(() => {
                this.startAutoUpdates();
            }, 5000);
        },
        
        bindEvents: function() {
            // Chart period buttons
            $('.sscf-chart-controls .button').on('click', this.changePeriod.bind(this));
            
            // Refresh data button
            $(document).on('click', '.sscf-refresh-data', this.refreshAllData.bind(this));
        },
        
        changePeriod: function(e) {
            e.preventDefault();
            
            if (this.isUpdating) return; // Prevent multiple simultaneous updates
            
            const $button = $(e.target);
            const period = parseInt($button.data('period'));
            
            // Update button states
            $('.sscf-chart-controls .button').removeClass('button-primary').addClass('button-secondary');
            $button.removeClass('button-secondary').addClass('button-primary');
            
            this.currentPeriod = period;
            this.updateCharts();
        },
        
        createEmptyCharts: function() {
            this.createTimelineChart();
            this.createMethodsChart();
            this.createTypesChart();
        },
        
        destroyChart: function(chartName) {
            if (this.charts[chartName]) {
                this.charts[chartName].destroy();
                this.charts[chartName] = null;
                delete this.charts[chartName];
            }
        },
        
        createTimelineChart: function() {
            const ctx = document.getElementById('sscf-timeline-chart');
            if (!ctx) return;
            
            // Destroy existing chart to prevent duplicates
            this.destroyChart('timeline');
            
            this.charts.timeline = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [
                        {
                            label: 'Spam Blocked',
                            data: [],
                            borderColor: '#dc3232',
                            backgroundColor: 'rgba(220, 50, 50, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 3,
                            pointHoverRadius: 5
                        },
                        {
                            label: 'Clean Submissions',
                            data: [],
                            borderColor: '#46b450',
                            backgroundColor: 'rgba(70, 180, 80, 0.1)',
                            fill: true,
                            tension: 0.4,
                            pointRadius: 3,
                            pointHoverRadius: 5
                        }
                    ]
                },
                options: {
                    animation: false,
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            enabled: true,
                            mode: 'index',
                            intersect: false,
                            animation: false
                        }
                    },
                    scales: {
                        x: {
                            display: true,
                            title: {
                                display: true,
                                text: 'Date'
                            },
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            display: true,
                            title: {
                                display: true,
                                text: 'Count'
                            },
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        }
                    }
                }
            });
        },
        
        createMethodsChart: function() {
            const ctx = document.getElementById('sscf-methods-chart');
            if (!ctx) return;
            
            // Destroy existing chart
            this.destroyChart('methods');
            
            this.charts.methods = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: [
                            '#dc3232',
                            '#ffb900',
                            '#00a0d2',
                            '#46b450',
                            '#826eb4',
                            '#f56e28'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    animation: false,
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                usePointStyle: true
                            }
                        },
                        tooltip: {
                            enabled: true,
                            animation: false,
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        },
        
        createTypesChart: function() {
            const ctx = document.getElementById('sscf-types-chart');
            if (!ctx) return;
            
            // Destroy existing chart
            this.destroyChart('types');
            
            this.charts.types = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: [
                            '#0073aa',
                            '#00a0d2',
                            '#826eb4',
                            '#f56e28'
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    animation: false,
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                usePointStyle: true
                            }
                        },
                        tooltip: {
                            enabled: true,
                            animation: false,
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        },
        
        updateCharts: function() {
            if (this.isUpdating) {
                console.log('Update already in progress, skipping...');
                return;
            }
            
            this.isUpdating = true;
            console.log('Fetching analytics data...');
            
            this.fetchAnalyticsData()
                .then(data => {
                    console.log('Analytics data received:', data);
                    this.updateTimelineChart(data.timeline);
                    this.updateMethodsChart(data.methods);
                    this.updateTypesChart(data.types);
                    this.isUpdating = false;
                })
                .catch(error => {
                    console.error('Error updating charts:', error);
                    this.isUpdating = false;
                });
        },
        
        updateTimelineChart: function(data) {
            if (!this.charts.timeline || !data) {
                console.log('Timeline chart or data not available');
                return;
            }
            
            const chart = this.charts.timeline;
            
            // Ensure we have arrays
            const labels = Array.isArray(data.labels) ? data.labels : [];
            const spam = Array.isArray(data.spam) ? data.spam : [];
            const clean = Array.isArray(data.clean) ? data.clean : [];
            
            // Limit data points to prevent infinite spreading
            const maxPoints = this.maxDataPoints;
            const limitedLabels = labels.slice(-maxPoints);
            const limitedSpam = spam.slice(-maxPoints);
            const limitedClean = clean.slice(-maxPoints);
            
            // IMPORTANT: Replace data, don't append
            chart.data.labels = limitedLabels;
            chart.data.datasets[0].data = limitedSpam;
            chart.data.datasets[1].data = limitedClean;
            
            // Update without animation
            chart.update('none');
            
            console.log('Timeline chart updated with', limitedLabels.length, 'data points');
        },
        
        updateMethodsChart: function(data) {
            if (!this.charts.methods || !data) {
                console.log('Methods chart or data not available');
                return;
            }
            
            const chart = this.charts.methods;
            
            // Ensure we have arrays
            const labels = Array.isArray(data.labels) ? data.labels : [];
            const values = Array.isArray(data.values) ? data.values : [];
            
            // IMPORTANT: Replace data, don't append
            chart.data.labels = labels;
            chart.data.datasets[0].data = values;
            
            // Update without animation
            chart.update('none');
            
            console.log('Methods chart updated with', labels.length, 'categories');
        },
        
        updateTypesChart: function(data) {
            if (!this.charts.types || !data) {
                console.log('Types chart or data not available');
                return;
            }
            
            const chart = this.charts.types;
            
            // Ensure we have arrays
            const labels = Array.isArray(data.labels) ? data.labels : [];
            const values = Array.isArray(data.values) ? data.values : [];
            
            // IMPORTANT: Replace data, don't append
            chart.data.labels = labels;
            chart.data.datasets[0].data = values;
            
            // Update without animation
            chart.update('none');
            
            console.log('Types chart updated with', labels.length, 'categories');
        },
        
        fetchAnalyticsData: function() {
            return new Promise((resolve, reject) => {
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
                        if (response.success && response.data) {
                            resolve(response.data);
                        } else {
                            // Return empty data structure on failure
                            resolve({
                                timeline: { labels: [], spam: [], clean: [] },
                                methods: { labels: [], values: [] },
                                types: { labels: [], values: [] }
                            });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.warn('AJAX error:', status, error);
                        // Return empty data structure on error
                        resolve({
                            timeline: { labels: [], spam: [], clean: [] },
                            methods: { labels: [], values: [] },
                            types: { labels: [], values: [] }
                        });
                    }
                });
            });
        },
        
        refreshAllData: function() {
            if (!this.isUpdating) {
                console.log('Refreshing all data...');
                this.updateCharts();
                this.updateStats();
            }
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
                    }
                },
                error: function(xhr, status, error) {
                    console.warn('Stats update error:', status, error);
                }
            });
        },
        
        startAutoUpdates: function() {
            // Refresh data every 10 minutes
            this.updateInterval = setInterval(() => {
                if (!this.isUpdating) {
                    this.refreshAllData();
                }
            }, 600000); // 10 minutes
            console.log('Auto-updates started (10 minute interval)');
        },
        
        stopAutoUpdates: function() {
            if (this.updateInterval) {
                clearInterval(this.updateInterval);
                this.updateInterval = null;
                console.log('Auto-updates stopped');
            }
        },
        
        cleanup: function() {
            this.stopAutoUpdates();
            this.destroyChart('timeline');
            this.destroyChart('methods');
            this.destroyChart('types');
        }
    };
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        // Only initialize if we're on the dashboard page
        if ($('#sscf-timeline-chart').length > 0) {
            SSCFDashboard.init();
        }
    });
    
    // Cleanup on page unload
    $(window).on('beforeunload', function() {
        if (typeof SSCFDashboard !== 'undefined') {
            SSCFDashboard.cleanup();
        }
    });
    
})(jQuery);
