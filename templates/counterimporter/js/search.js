/**
 * This file is part of the {@link http://amsl.technology amsl} project.
 *
 * @author Norman Radtke
 * @copyright Copyright (c) 2015, {@link http://ub.uni-leipzig.de Leipzig University Library}
 * @license http://opensource.org/licenses/gpl-license.php GNU General Public License (GPL)
 */

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

var licensor_organization;
licensor_organization = new Bloodhound({
    datumTokenizer: function (datum) {
        return Bloodhound.tokenizers.whitespace(datum.name);
    },
    queryTokenizer: Bloodhound.tokenizers.whitespace,
    limit: i,
    prefetch: {
        url: urlBase + 'counterimporter/getlicensororganizations'
    }
});
licensor_organization.initialize();

var licensee_organization;
licensee_organization = new Bloodhound({
    datumTokenizer: function (datum) {
        return Bloodhound.tokenizers.whitespace(datum.name);
    },
    queryTokenizer: Bloodhound.tokenizers.whitespace,
    limit: i,
    prefetch: {
        url: urlBase + 'counterimporter/getlicenseeorganizations'
    }
});
licensee_organization.initialize();


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

    licensor_organization.clearPrefetchCache();
    sourceOrganization  = '<p>';
    sourceOrganization += '<span class="highlight-title">{{name}}</span><!--<br><span class="origin-index">URI: {{org}}</span>--><br>';
    sourceOrganization += '</p>';
    $('#licensor-organization-input.typeahead').typeahead(null, {
        name: 'organization-matches',
        displayKey: 'org',
        source: licensor_organization.ttAdapter(),
        templates: {
            empty: ['<div class="empty-message">', '<strong>' + noResults + '</strong>', '</div>'].join('\n'),
            suggestion: Handlebars.compile(sourceOrganization),
            footer: '<div class="empty-message">A Maximum of ' + i + ' results are shown.</div>'
        }
    });

    licensee_organization.clearPrefetchCache();
    sourceOrganization  = '<p>';
    sourceOrganization += '<span class="highlight-title">{{name}}</span><!--<br><span class="origin-index">URI: {{org}}</span>--><br>';
    sourceOrganization += '</p>';
    $('#licensee-organization-input.typeahead').typeahead(null, {
        name: 'organization-matches',
        displayKey: 'org',
        source: licensee_organization.ttAdapter(),
        templates: {
            empty: ['<div class="empty-message">', '<strong>' + noResults + '</strong>', '</div>'].join('\n'),
            suggestion: Handlebars.compile(sourceOrganization),
            footer: '<div class="empty-message">A Maximum of ' + i + ' results are shown.</div>'
        }
    });
    $( "#datepicker1" ).datepicker({ dateFormat: 'yy-mm-dd' });
    $( "#datepicker2" ).datepicker({ dateFormat: 'yy-mm-dd' });
});
