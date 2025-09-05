<?php
class Content_Discussions_Summarizer {

    public function init() {
        // Register Custom Post Type
        add_action('init', array($this, 'register_cpt'));
        
        // Add Meta Box
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        
        // Save Meta Box Data
        add_action('save_post_community_discussion', array($this, 'save_meta_box'), 10, 2);
        
        // Handle AJAX request
        add_action('wp_ajax_generate_content_summary', array($this, 'handle_ajax_summary_generation'));
        
        // Enqueue Admin Scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Display Summary on Front-end
        add_filter('the_content', array($this, 'display_summary_on_frontend'));
        
        // Register Settings
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_settings_page'));
    }

    public function register_cpt() {
        $labels = array(
            'name' => __('Community Discussions', 'community-discussions'),
            'singular_name' => __('Discussion', 'community-discussions'),
            'menu_name' => __('Discussions', 'community-discussions'),
            'name_admin_bar' => __('Discussion', 'community-discussions'),
            'add_new' => __('Add New', 'community-discussions'),
            'add_new_item' => __('Add New Discussion', 'community-discussions'),
            'new_item' => __('New Discussion', 'community-discussions'),
            'edit_item' => __('Edit Discussion', 'community-discussions'),
            'view_item' => __('View Discussion', 'community-discussions'),
            'all_items' => __('All Discussions', 'community-discussions'),
            'search_items' => __('Search Discussions', 'community-discussions'),
            'not_found' => __('No discussions found.', 'community-discussions'),
            'not_found_in_trash' => __('No discussions found in Trash.', 'community-discussions')
        );

        $args = array(
            'labels' => $labels,
            'public' => true,
            'has_archive' => true,
            'supports' => array('title', 'editor', 'author', 'thumbnail'),
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-groups',
            'capability_type' => 'post',
        );
        register_post_type('community_discussion', $args);
    }

    public function add_meta_box() {
        add_meta_box(
            'cds_summary_meta_box',
            __('Content Summary', 'community-discussions'),
            array($this, 'render_meta_box'),
            'community_discussion',
            'side',
            'default'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('cds_save_summary', 'cds_summary_nonce');

        $summary = get_post_meta($post->ID, '_cds_summary', true);
        $summary_length = get_option('cds_summary_length', 100);
        $current_scope = get_post_meta($post->ID, '_cds_summary_scope', true);
        
        if (empty($current_scope)) {
            $current_scope = 'first_sentences';
        }

        ?>
        <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 8px;">
            <h4 style="margin-top: 0; color: #2c5282;">
                <?php _e('Content Summary Settings', 'community-discussions'); ?>
            </h4>
            
            <div style="margin: 15px 0;">
                <label for="cds_summary_length_current" style="display: block; font-weight: 600; color: #4a5568; margin-bottom: 5px;">
                    <?php _e('Summary Length (characters):', 'community-discussions'); ?>
                </label>
                <input type="number" 
                       name="cds_summary_length_current" 
                       id="cds_summary_length_current" 
                       value="<?php echo esc_attr($summary_length); ?>" 
                       min="50" 
                       max="500"
                       style="width: 100px; padding: 8px; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 14px;" />
            </div>

            <div style="margin: 15px 0;">
                <label style="display: block; font-weight: 600; color: #4a5568; margin-bottom: 5px;">
                    <?php _e('Summary Style:', 'community-discussions'); ?>
                </label>
                <select name="cds_summary_scope" style="width: 100%; padding: 8px; border: 1px solid #cbd5e0; border-radius: 4px; font-size: 14px;">
                    <option value="first_sentences" <?php selected($current_scope, 'first_sentences'); ?>><?php _e('First Sentences', 'community-discussions'); ?></option>
                    <option value="key_points" <?php selected($current_scope, 'key_points'); ?>><?php _e('Key Points', 'community-discussions'); ?></option>
                    <option value="beginning_end" <?php selected($current_scope, 'beginning_end'); ?>><?php _e('Beginning + Conclusion', 'community-discussions'); ?></option>
                </select>
            </div>
        </div>

        <p>
            <button type="button" id="cds-generate-summary" class="button button-primary">
                <?php _e('Generate Content Summary', 'community-discussions'); ?>
            </button>
            <span class="spinner" style="float: none; margin-left: 5px;"></span>
        </p>
        
        <p style="color: #718096; font-size: 13px; margin: 8px 0 12px 0;">
            <?php printf(__('Summary will be approximately %d characters.', 'community-discussions'), $summary_length); ?>
        </p>
        
        <textarea name="cds_summary" id="cds-summary" style="width: 100%; min-height: 120px; margin-top: 10px; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px;" placeholder="<?php _e('Content summary will appear here...', 'community-discussions'); ?>"><?php echo esc_textarea($summary); ?></textarea>
        <?php
    }

    public function enqueue_admin_scripts($hook) {
        global $post;

        if (($hook == 'post.php' || $hook == 'post-new.php') && isset($post) && $post->post_type == 'community_discussion') {
            
            wp_enqueue_script('wp-api');
            wp_enqueue_script('wp-api-fetch');
            
            wp_enqueue_script(
                'cds-admin-js', 
                CDS_PLUGIN_URL . 'assets/admin.js', 
                array('jquery', 'wp-api', 'wp-api-fetch'), 
                '1.0.0', 
                true
            );

            wp_localize_script('cds-admin-js', 'cds_ajax_object', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cds_ajax_nonce'),
                'post_id' => $post->ID,
                'generating_text' => __('Generating...', 'community-discussions'),
            ));
        }
    }

    public function handle_ajax_summary_generation() {
        // Turn off error display to prevent breaking JSON
        @ini_set('display_errors', 0);
        @error_reporting(0);
        
        // 1. Check nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'cds_ajax_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
            wp_die();
        }

        // 2. Check user permissions
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
            wp_die();
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        // Get content from AJAX request
        $post_content = isset($_POST['post_content']) ? wp_kses_post(wp_unslash($_POST['post_content'])) : '';
        
        // If no content from AJAX, get from database
        if (empty($post_content)) {
            $post = get_post($post_id);
            if ($post) {
                $post_content = $post->post_content;
            }
        }
        
        if (empty($post_content)) {
            wp_send_json_error(array('message' => 'Post content is empty. Please add some content first.'));
            wp_die();
        }

        // Get settings from AJAX request
        $summary_length = isset($_POST['length']) ? intval($_POST['length']) : get_option('cds_summary_length', 100);
        $summary_scope = isset($_POST['scope']) ? sanitize_text_field($_POST['scope']) : 'first_sentences';

        // Generate summary
        $generated_summary = $this->generate_content_summary($post_content, $summary_length, $summary_scope);
        
        if (empty($generated_summary)) {
            wp_send_json_error(array('message' => 'Failed to generate summary. Content might be too short.'));
            wp_die();
        }

        $sanitized_summary = wp_kses_post($generated_summary);
        
        // Save the summary and settings
        update_post_meta($post_id, '_cds_summary', $sanitized_summary);
        update_post_meta($post_id, '_cds_summary_length', $summary_length);
        update_post_meta($post_id, '_cds_summary_scope', $summary_scope);

        // Send clean JSON response
        wp_send_json_success(array('summary' => $sanitized_summary));
    }

    private function generate_content_summary($content, $length, $scope = 'first_sentences') {
    $length = intval($length);
    usleep(300000);
    
    $plain_text = wp_strip_all_tags($content);
    $plain_text = trim($plain_text);
    
    if (strlen($plain_text) <= $length) {
        return $plain_text;
    }
    
    // Extract sentences
    $sentences = [];
    $current = '';
    
    for ($i = 0; $i < strlen($plain_text); $i++) {
        $char = $plain_text[$i];
        $current .= $char;
        
        // Sentence ends with . ! ? followed by space and capital
        if (($char == '.' || $char == '!' || $char == '?') && 
            isset($plain_text[$i+1]) && $plain_text[$i+1] == ' ' && 
            isset($plain_text[$i+2]) && ctype_upper($plain_text[$i+2])) {
            $sentences[] = trim($current);
            $current = '';
        }
    }
    
    // Add the last sentence
    if (!empty(trim($current))) {
        $sentences[] = trim($current);
    }
    
    // If manual parsing failed, use simple split
    if (empty($sentences)) {
        $parts = explode('.', $plain_text);
        foreach ($parts as $part) {
            $part = trim($part);
            if (!empty($part)) {
                $sentences[] = $part . '.';
            }
        }
    }
    
    // Clean sentences
    $sentences = array_filter($sentences);
    $sentence_count = count($sentences);
    
    // Generate UNIQUE summaries for each style
    switch ($scope) {
        case 'key_points':
            // KEY POINTS: Bullet point style with line breaks
            $key_points = [];
            
            if ($sentence_count >= 4) {
                $key_points[] = "• " . $this->create_bullet_point($sentences[0], "primary");
                $key_points[] = "• " . $this->create_bullet_point($sentences[floor($sentence_count * 0.3)], "benefit");
                $key_points[] = "• " . $this->create_bullet_point($sentences[floor($sentence_count * 0.6)], "example");
                $key_points[] = "• " . $this->create_bullet_point(end($sentences), "impact");
            } 
            else if ($sentence_count == 3) {
                $key_points[] = "• " . $this->create_bullet_point($sentences[0], "primary");
                $key_points[] = "• " . $this->create_bullet_point($sentences[1], "benefit");
                $key_points[] = "• " . $this->create_bullet_point($sentences[2], "impact");
            }
            else if ($sentence_count == 2) {
                $key_points[] = "• " . $this->create_bullet_point($sentences[0], "primary");
                $key_points[] = "• " . $this->create_bullet_point($sentences[1], "impact");
            }
            else {
                $key_points[] = "• " . $this->create_bullet_point($sentences[0], "primary");
            }
            
            $summary = implode("\n\n", $key_points);
            break;
            
        case 'beginning_end':
            // BEGINNING + CONCLUSION: Clear separation with line break
            if ($sentence_count >= 3) {
                $introduction = "📌 " . $this->summarize_as_intro($sentences[0]);
                $conclusion = "🎯 " . $this->summarize_as_conclusion(end($sentences));
                $summary = $introduction . "\n\n" . $conclusion;
            }
            else if ($sentence_count == 2) {
                $summary = "📌 " . $this->summarize_as_intro($sentences[0]) . "\n\n" . 
                          "🎯 " . $this->summarize_as_conclusion($sentences[1]);
            }
            else {
                $summary = "📌 " . $this->summarize_as_intro($sentences[0]);
            }
            break;
            
        case 'first_sentences':
        default:
            // FIRST SENTENCES: Clean paragraph with proper spacing
            $first_sentences = array_slice($sentences, 0, min(3, $sentence_count));
            $summary = "✨ " . $this->create_paragraph_summary($first_sentences);
            break;
    }
    
    // Trim to length while preserving line breaks
    if (strlen($summary) > $length) {
        $summary = $this->smart_truncate($summary, $length);
    }
    
    return trim($summary);
}

// ADD THESE NEW HELPER METHODS:

private function create_bullet_point($sentence, $type) {
    // Simple bullet point formatting - keeps your existing logic
    $point = $sentence;
    
    // Add some visual cues based on type
    $prefixes = [
        "primary" => "🏛️ ",
        "benefit" => "✅ ", 
        "example" => "📊 ",
        "impact" => "🎯 "
    ];
    
    if (isset($prefixes[$type])) {
        $point = $prefixes[$type] . $point;
    }
    
    // Ensure concise bullet points
    if (strlen($point) > 120) {
        $words = explode(' ', $point);
        if (count($words) > 18) {
            $point = implode(' ', array_slice($words, 0, 15)) . '...';
        }
    }
    
    return $point;
}

private function summarize_as_intro($sentence) {
    // Keep your existing intro logic, just ensure it's concise
    $intro = preg_replace([
        '/\b(We|Our|The)\b/i',
        '/\b(important|critical|crucial)\b/i',
    ], [
        'This analysis reveals',
        'highly impactful',
    ], $sentence);
    
    return $intro;
}

private function summarize_as_conclusion($sentence) {
    // Keep your existing conclusion logic
    $conclusion = preg_replace([
        '/\b(in summary|in conclusion|overall)\b/i',
        '/\b(should|ought to|must)\b/i',
    ], [
        'Ultimately',
        'strategically',
    ], $sentence);
    
    return $conclusion;
}

private function create_paragraph_summary($sentences) {
    // Your existing paragraph logic - just ensure clean output
    $paragraph = implode(' ', $sentences);
    
    $paragraph = preg_replace([
        '/\b(and|but|however|moreover)\b\s+/i',
        '/\s{2,}/',
        '/\.(\s+[A-Z])/'
    ], [
        '',
        ' ',
        '. $1'
    ], $paragraph);
    
    return $paragraph;
}

private function smart_truncate($text, $length) {
    if (strlen($text) <= $length) {
        return $text;
    }
    
    // Preserve line breaks in truncation
    $truncated = substr($text, 0, $length);
    
    // Find the last line break or space
    $last_break = max(
        strrpos($truncated, "\n"),
        strrpos($truncated, ' ')
    );
    
    if ($last_break !== false && $last_break > $length * 0.7) {
        return substr($text, 0, $last_break) . '...';
    }
    
    return $truncated . '...';
}
// Helper functions for different summary styles
    private function create_key_point($sentence, $type) {
    $transformations = [
        "primary" => [
            '/\b(volunteer programs|community initiatives|local efforts)\b/i' => '🌟 $0',
            '/\b(play a critical role|are essential|provide crucial)\b/i' => 'significantly $0'
        ],
        "benefit" => [
            '/\b(strengthen|build|create|develop)\b/i' => '✅ $0',
            '/\b(trust|solidarity|cohesion|resilience)\b/i' => 'strong $0'
        ],
        "example" => [
            '/\b(for example|for instance|such as|including)\b/i' => '📊 $0',
            '/\b(students|volunteers|participants|members)\b/i' => 'active $0'
        ],
        "impact" => [
            '/\b(ultimately|finally|in conclusion|overall)\b/i' => '🎯 $0',
            '/\b(ensure|guarantee|lead to|result in)\b/i' => 'directly $0'
        ]
    ];
    
    $point = $sentence;
    if (isset($transformations[$type])) {
        $point = preg_replace(array_keys($transformations[$type]), array_values($transformations[$type]), $sentence);
    }
    
    // Simplify the point
    $point = preg_replace('/\s+/', ' ', $point);
    $point = trim($point);
    
    return $point;
}



    private function create_narrative_summary($sentences) {
    // Create a cohesive narrative from opening sentences
    $narrative = implode(' ', $sentences);
    
    // Improve flow and remove redundancies
    $narrative = preg_replace([
        '/\b(and|but|however|moreover)\b\s+/i',
        '/\s{2,}/',
        '/\.(\s+[A-Z])/'
    ], [
        '',
        ' ',
        '. $1'
    ], $narrative);
    
    return $narrative;
}

// Helper functions for bullet points and clear formatting


    public function save_meta_box($post_id, $post) {
        // Check nonce
        if (!isset($_POST['cds_summary_nonce']) || 
            !wp_verify_nonce($_POST['cds_summary_nonce'], 'cds_save_summary')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id) || 
            (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || 
            $post->post_type !== 'community_discussion') {
            return;
        }

        // Save summary
        if (isset($_POST['cds_summary'])) {
            $summary = wp_kses_post($_POST['cds_summary']);
            update_post_meta($post_id, '_cds_summary', $summary);
        }

        // Save summary length
        if (isset($_POST['cds_summary_length_current'])) {
            $new_length = intval($_POST['cds_summary_length_current']);
            update_post_meta($post_id, '_cds_summary_length', $new_length);
        }

        // Save summary scope
        if (isset($_POST['cds_summary_scope'])) {
            $scope = sanitize_text_field($_POST['cds_summary_scope']);
            update_post_meta($post_id, '_cds_summary_scope', $scope);
        }
    }

    public function display_summary_on_frontend($content) {
    if (!is_singular('community_discussion') || !is_main_query() || !in_the_loop()) {
        return $content;
    }

    $summary = get_post_meta(get_the_ID(), '_cds_summary', true);

    if (!empty($summary)) {
        // Convert line breaks and bullet points to HTML
        $formatted_summary = nl2br(esc_html($summary));
        
        // Add basic styling for bullet points
        $formatted_summary = preg_replace('/•/', '<span style="color: #667eea; font-weight: bold; margin-right: 8px;">•</span>', $formatted_summary);
        
        $summary_html = '
        <div class="cds-professional-summary" style="
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            padding: 2.5rem;
            margin: 2.5rem 0;
            color: white;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            position: relative;
            overflow: hidden;
            border-left: 5px solid #ff6b6b;
        ">
            <div style="
                position: absolute;
                top: -20px;
                right: -20px;
                width: 100px;
                height: 100px;
                background: rgba(255,255,255,0.1);
                border-radius: 50%;
            "></div>
            
            <div style="
                position: relative;
                z-index: 2;
            ">
                <div style="
                    display: flex;
                    align-items: center;
                    margin-bottom: 1.5rem;
                    gap: 12px;
                ">
                    <div style="
                        background: rgba(255,255,255,0.2);
                        padding: 12px;
                        border-radius: 12px;
                        backdrop-filter: blur(10px);
                    ">
                        <svg style="width: 24px; height: 24px; fill: white;" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                        </svg>
                    </div>
                    
                    <h3 style="
                        margin: 0;
                        font-size: 1.5rem;
                        font-weight: 700;
                        color: white;
                        text-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    ">
                        📋 Executive Summary
                    </h3>
                </div>
                
                <div style="
                    background: rgba(255,255,255,0.95);
                    padding: 1.8rem;
                    border-radius: 12px;
                    color: #2d3748;
                    line-height: 1.7;
                    font-size: 1.05rem;
                    box-shadow: 0 8px 25px rgba(0,0,0,0.1);
                    border: 1px solid rgba(255,255,255,0.2);
                ">
                    <div style="
                        font-weight: 500;
                        line-height: 1.8;
                    ">' . $formatted_summary . '</div>
                </div>
                
                <div style="
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    margin-top: 1.2rem;
                    font-size: 0.9rem;
                    opacity: 0.9;
                ">
                    <span style="
                        background: rgba(255,255,255,0.2);
                        padding: 6px 12px;
                        border-radius: 20px;
                        backdrop-filter: blur(10px);
                    ">
                        🚀 AI-Powered Insight
                    </span>
                    
                    <span style="font-style: italic;">
                        Generated by Community Intelligence
                    </span>
                </div>
            </div>
        </div>';
        
        $content = $summary_html . $content;
    }

    return $content;
}    
    public function register_settings() {
        register_setting(
            'cds_settings_group',
            'cds_summary_length',
            array(
                'type' => 'integer',
                'sanitize_callback' => 'absint',
                'default' => 100,
            )
        );

        add_settings_section(
            'cds_settings_section',
            __('Summary Settings', 'community-discussions'),
            null,
            'cds_settings'
        );

        add_settings_field(
            'cds_summary_length_field',
            __('Summary Length (characters)', 'community-discussions'),
            array($this, 'render_summary_length_field'),
            'cds_settings',
            'cds_settings_section'
        );
    }

    public function render_summary_length_field() {
        $value = get_option('cds_summary_length', 100);
        echo '<input type="number" name="cds_summary_length" value="' . esc_attr($value) . '" min="50" max="500" />';
        echo '<p class="description">' . __('The maximum length of the summary in characters.', 'community-discussions') . '</p>';
    }

    public function add_settings_page() {
        add_options_page(
            __('Discussion Summary Settings', 'community-discussions'),
            __('Content Summarizer', 'community-discussions'),
            'manage_options',
            'cds-settings',
            array($this, 'render_settings_page')
        );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Discussion Summary Settings', 'community-discussions'); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('cds_settings_group');
                do_settings_sections('cds_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
