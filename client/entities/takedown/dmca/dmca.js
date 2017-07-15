import { Record, Set, List, fromJS } from 'immutable';
import { Post } from './post';

export class Dmca extends Record( {
	lumenSend: undefined,
	lumenTitle: undefined,
	senderName: undefined,
	senderPerson: undefined,
	senderFirm: undefined,
	senderAddress: new List(),
	senderCity: undefined,
	senderState: undefined,
	senderZip: undefined,
	senderCountryCode: undefined,
	sent: undefined,
	actionTakenId: undefined,
	pageIds: new Set(),
	originalUrls: new List(),
	method: undefined,
	subject: undefined,
	body: undefined,
	fileIds: new List(),
	wmfSend: undefined,
	wmfTitle: undefined,
	commonsSend: undefined,
	commonsPost: new Post(),
	commonsVillagePumpSend: undefined,
	commonsVillagePumpPost: new Post(),
	userNoticeIds: new Set()
} ) {
	constructor( data = {} ) {
		data = {
			...data,
			senderAddress: new List( data.senderAddress || [] ),
			pageIds: new Set( data.pageIds || [] ),
			originalUrls: fromJS( data.originalUrls || [] ).toOrderedMap(),
			fileIds: fromJS( data.fileIds || [] ).toList(),
			commonsPost: new Post( data.commonsPost || {} ),
			commonsVillagePumpPost: new Post( data.commonsVillagePumpPost || {} ),
			userNotices: new Set( data.noticeUsers || [] ),
			userNoticeIds: new Set( data.userNoticeIds || [] )
		};
		super( data );
	}

}