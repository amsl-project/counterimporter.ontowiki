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
var sushi = new Bloodhound({
    datumTokenizer: function(dat) {
        return Bloodhound.tokenizers.whitespace(dat.label);
    },
    queryTokenizer: Bloodhound.tokenizers.whitespace,
    limit: 20,
    prefetch: {
        url: urlBase + 'counterimporter/getsushi'
    }
});

sushi.initialize();
/**
 * Now use the input fields to find SUSHI settings while typing
 */
$(document).ready(function() {
    // dynamically set input value to value of search text.
    // This is needed to search for input if enter was pressed.
    /*$("#vendor-input").on("input", function() {
        input = $('#vendor-input').val();
        $("#temp-input").text(input);
    });*/

    // every result gets a paragraph containing the label and other helpfull information
    sushi.clearPrefetchCache();
    sourceSushi  = '<p>';
    sourceSushi += '<span class="highlight-title">{{label}}</span><br><!--<span class="origin-index">Report-Typ: {{reportName}}</span><--><br>';
    sourceSushi += '</p>';
    var noResults = 'No results found';
    var trigger = 'Press enter to trigger an advanced search';
    $('#sushi-input.typeahead').typeahead(null, {
        name: 'sushi-matches',
        displayKey: 's',
        source: sushi.ttAdapter(),
        templates: {
            empty: ['<div class="empty-message">', '<strong>' + noResults + '</strong><p>' + trigger + '</p?>', '</div>'].join('\n'),
            suggestion: Handlebars.compile(sourceSushi),
            footer: '<div class="empty-message">Maximal 20 results are shown. Press Enter to see all.</div>'
        }
    });
    $( "#datepicker1" ).datepicker({ dateFormat: 'yy-mm-dd' });
    $( "#datepicker2" ).datepicker({ dateFormat: 'yy-mm-dd' });
});
