define('phalanx', ['jquery', 'wikia.nirvana'], function($, nirvana) {
	var TOKEN;

	function init(token) {
		TOKEN = token;
	}

	function validate(regexp, callback) {
		var dfd = new $.Deferred();

		nirvana.postJson('PhalanxSpecial', 'validate', {
			regexp: regexp,
			token: TOKEN
		}, function(resp) {
			// possible values:
			//  0 - not valid
			//  1 - not valid
			//  false - service did not respond
			if (resp === false) {
				dfd.reject();
			}
			else {
				dfd.resolve(resp && resp.valid === 1);
			}
		}, function() {
			dfd.reject();
		});

		return dfd.promise();
	}

	function unblock(blockId) {
		var dfd = new $.Deferred();

		nirvana.postJson('PhalanxSpecial', 'unblock', {
			blockId: blockId,
			token: TOKEN
		}, function(resp) {
			if (resp && resp.success) {
				dfd.resolve(true);
			}
			else {
				dfd.reject();
			}
		}, function() {
			dfd.reject();
		});

		return dfd.promise();
	}

	// API
	return {
		init: init,
		validate: validate,
		unblock: unblock
	}
});