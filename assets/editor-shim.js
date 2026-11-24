/*
 * Newspack Newsletters looks up the active provider's editor hooks in a hardcoded object literal
 * (`SERVICE_PROVIDERS[ serviceProvider ]` in src/service-providers/index.js of the newspack-newsletters
 * plugin) and offers no filter or registry for third-party providers, so its Send button crashes for any
 * provider it does not ship. Defining our entry on Object.prototype is the only way that lookup can find
 * it. The property is non-enumerable (invisible to for...in, Object.keys and spreads), configurable, and
 * only loaded on the newsletter editor screen.
 */
( function () {
	const strings = window.presspass_ses_editor;

	Object.defineProperty( Object.prototype, 'presspass_ses', {
		configurable: true,
		enumerable: false,
		value: {
			displayName: strings.displayName,
			renderPreSendInfo( newsletterData, meta ) {
				const list = newsletterData.lists.find( ( sendList ) => sendList.id === meta.send_list_id );
				return wp.element.createElement(
					'p',
					null,
					strings.preSendInfo.replace( '%1$s', list.name ).replace( '%2$s', list.count )
				);
			},
			isCampaignSent( newsletterData, status ) {
				return status === 'publish' || status === 'private';
			},
		},
	} );
}() );
