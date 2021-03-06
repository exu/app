describe('Toc module', function() {
	'use strict';

	var sections = {
			open: function(){}
		},
		track = {
			event: function(){},
			CLICK: ''
		},
		body = getBody(),
		windowMock = {
			document: {
				body: body,
				getElementById: function(id){
					return body.querySelector('#' + id);
				}
			}
		},
		toc = modules.toc(track, sections, windowMock, jQuery);

	it('should be defined', function(){
		expect(toc).toBeDefined();
		expect(typeof toc.init).toEqual('function');
		expect(typeof toc.open).toEqual('function');
		expect(typeof toc.close).toEqual('function');
	});

	it('should init', function(done){
		var d = windowMock.document;

		body.innerHTML = '<div id="mw-content-text"><table id="toc" class="toc"><div id="toctitle"></div></table></div>';

		toc.init();

		expect(d.body.className).toMatch('hasToc');

		var chev = d.getElementById('toctitle').querySelectorAll('.chev');
		expect(chev.length).toEqual(1);
	});

	it('should open/close toc', function(){
		var d = windowMock.document;

		body.innerHTML = '<div id="mw-content-text"><table id="toc" class="toc"><div id="toctitle"></div></table></div>';

		toc.init();
		toc.open();

		expect(d.getElementById('toc').className).toMatch('open');
		expect(d.body.className).toMatch('hidden');

		toc.close();

		expect(d.getElementById('toc').className).not.toMatch('open');
		expect(body.className).not.toMatch('hidden');
	})
});
