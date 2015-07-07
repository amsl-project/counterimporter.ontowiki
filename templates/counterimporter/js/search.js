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
var sushi;
var i = 10;
sushi = new Bloodhound({
    datumTokenizer: function (dat) {
        return Bloodhound.tokenizers.whitespace(dat.label);
    },
    queryTokenizer: Bloodhound.tokenizers.whitespace,
    limit: i,
    prefetch: {
        url: urlBase + 'counterimporter/getsushivendors'
    }
});
sushi.initialize();

var organization;
organization = new Bloodhound({
    datumTokenizer: function (datum) {
        return Bloodhound.tokenizers.whitespace(datum.name);
    },
    queryTokenizer: Bloodhound.tokenizers.whitespace,
    limit: i,
    prefetch: {
        url: urlBase + 'counterimporter/getorganizations'
    }
});
organization.initialize();
/**
 * Now use the input fields to find SUSHI settings while typing
 */
$(document).ready(function() {

    // every result gets a paragraph containing the label and other helpfull information
    sushi.clearPrefetchCache();
    sourceSushi  = '<p>';
    sourceSushi += '<span class="highlight-title">{{label}}</span><br><!--<span class="origin-index">Report-Typ: {{reportName}}</span><--><br>';
    sourceSushi += '</p>';
    var noResults = 'No results found';
    $('#sushi-input.typeahead').typeahead(null, {
        name: 'sushi-matches',
        displayKey: 's',
        source: sushi.ttAdapter(),
        templates: {
            empty: ['<div class="empty-message">', '<strong>' + noResults + '</strong>', '</div>'].join('\n'),
            suggestion: Handlebars.compile(sourceSushi),
            footer: '<div class="empty-message">A maximum of ' + i + ' results are shown.</div>'
        }
    });
    organization.clearPrefetchCache();
    sourceOrganization  = '<p>';
    sourceOrganization += '<span class="highlight-title">{{name}}</span><br><span class="origin-index">URI: {{org}}</span><br>';
    sourceOrganization += '</p>';
    $('#organization-input.typeahead').typeahead(null, {
        name: 'organization-matches',
        displayKey: 'org',
        source: organization.ttAdapter(),
        templates: {
            empty: ['<div class="empty-message">', '<strong>' + noResults + '</strong>', '</div>'].join('\n'),
            suggestion: Handlebars.compile(sourceOrganization),
            footer: '<div class="empty-message">A Maximum of ' + i + ' results are shown.</div>'
        }
    });
    $( "#datepicker1" ).datepicker({ dateFormat: 'yy-mm-dd' });
    $( "#datepicker2" ).datepicker({ dateFormat: 'yy-mm-dd' });
});
