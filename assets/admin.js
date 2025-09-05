jQuery(document).ready(function($) {
    $('#cds-generate-summary').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var spinner = button.next('.spinner');
        var textarea = $('#cds-summary');
        
        console.log('Generate button clicked');
        
        // Get settings with defaults
        var scope = $('select[name="cds_summary_scope"]').val() || 'first_sentences';
        var length = $('input[name="cds_summary_length_current"]').val() || '100';
        
        console.log('Scope:', scope, 'Length:', length);

        // Get post content - SIMPLIFIED approach
        var postContent = '';
        
        // Method 1: Block Editor (Gutenberg)
        if (window.wp && window.wp.data && window.wp.data.select('core/editor')) {
            postContent = window.wp.data.select('core/editor').getEditedPostContent();
            console.log('Got content from Block Editor');
        }
        // Method 2: Classic Editor
        else if (window.tinymce && tinymce.get('content')) {
            var editor = tinymce.get('content');
            if (editor && !editor.isHidden()) {
                postContent = editor.getContent();
                console.log('Got content from Classic Editor');
            }
        }
        // Method 3: Fallback to textarea
        else {
            postContent = $('#content').val();
            console.log('Got content from textarea');
        }

        // Validate content
        if (!postContent || postContent.trim().length < 10) {
            alert('Error: Please add more content before generating a summary (minimum 10 characters).');
            return;
        }

        console.log('Content length:', postContent.length);

        // Show loading state
        button.prop('disabled', true).text(cds_ajax_object.generating_text);
        spinner.addClass('is-active');
        textarea.val('Generating summary...');

        // Create AJAX request with timeout
        var ajaxRequest = $.ajax({
            url: cds_ajax_object.ajax_url,
            type: 'POST',
            dataType: 'json',
            timeout: 30000, // 30 second timeout
            data: {
                action: 'generate_content_summary',
                nonce: cds_ajax_object.nonce,
                post_id: cds_ajax_object.post_id,
                post_content: postContent,
                scope: scope,
                length: length
            }
        });

        // Handle success
        ajaxRequest.done(function(response) {
            console.log('AJAX Success:', response);
            
            if (response && response.success && response.data && response.data.summary) {
                textarea.val(response.data.summary);
                console.log('Summary generated successfully');
            } else {
                var errorMsg = 'Unknown error occurred';
                if (response && response.data && response.data.message) {
                    errorMsg = response.data.message;
                }
                alert('Error: ' + errorMsg);
            }
        });

        // Handle failure
        ajaxRequest.fail(function(xhr, status, error) {
            console.error('AJAX Failed:', status, error);
            
            if (status === 'timeout') {
                alert('Request timeout. Please try again.');
            } else if (status === 'parsererror') {
                // Try to extract summary from response even if JSON is invalid
                try {
                    var responseText = xhr.responseText;
                    // Look for summary pattern in the response
                    var summaryMatch = responseText.match(/"summary":"([^"]+)"/);
                    if (summaryMatch && summaryMatch[1]) {
                        textarea.val(decodeURIComponent(summaryMatch[1]));
                        console.log('Recovered summary from response');
                    } else {
                        throw new Error('No summary found in response');
                    }
                } catch (e) {
                    alert('Server error: Could not parse response. Please check console for details.');
                }
            } else {
                alert('Network error: ' + error + '. Please try again.');
            }
        });

        // Always run on complete
        ajaxRequest.always(function() {
            button.prop('disabled', false).text('Generate Content Summary');
            spinner.removeClass('is-active');
            console.log('AJAX request completed');
        });
    });
});
