/**
 * This file is part of the {@link http://ontowiki.net OntoWiki} project.
 *
 * @copyright Copyright (c) 2013, {@link http://aksw.org AKSW}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */
/**
/**
 * Twitter Typehead tokenizer initialization.
 */
var organizations = new Bloodhound({
    datumTokenizer: function(data) {
        return Bloodhound.tokenizers.whitespace(data.label);
    },
    queryTokenizer: Bloodhound.tokenizers.whitespace,
    limit: 7,
    prefetch: {
        url: urlBase + 'counterimporter/getorganizations'
    }
});

organizations.initialize();
/**
 * Now the existing search field will be reused for the new search function.
 * To make sure the old search function is not interfering with the new one,
 * all existing event handlers are removed/unbound from the search field and
 * the new class typeahead is added.
 */
$(document).ready(function() {
    // dynamically set input value to value of search text.
    // This is needed to search for input if enter was pressed.
    /*$("#vendor-input").on("input", function() {
        input = $('#vendor-input').val();
        $("#temp-input").text(input);
    });*/
    // every result gets a paragraph containing the title and a visualization of the
    // the part elasticsearch has matched (highlight)
    organizations.clearPrefetchCache();
    source  = '<p>';
    source += '<strong class="highlight-title">{{label}}</strong><br><span class="origin-index">{{org}}</span><br>';
    //source += '<span class="hint--bottom" data-hint="{{label}}">';
    //source += '<span class="uri-suggestion">{{label}}</span>';
    //source += '</span>';
    source += '</p>';
    //source = '{{label}}';
    var noResults = 'No results found';
    var trigger = 'Press enter to trigger an advanced search';
    // indices
    var indices = 'bibo:periodical,bibrm:contractitem';
    $('#vendor-input.typeahead').typeahead(null, {
        name: 'org-matches',
        displayKey: 'org',
        source: organizations.ttAdapter(),
        templates: {
            empty: ['<div class="empty-message">', '<strong>' + noResults + '</strong><p>' + trigger + '</p?>', '</div>'].join('\n'),
            suggestion: Handlebars.compile(source),
            footer: '<div class="empty-message">Maximal 7 results are shown. Press Enter to see all.</div>'
        }
    });
});
