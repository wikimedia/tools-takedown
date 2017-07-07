import { Observable, Subject } from 'rxjs';
import 'rxjs/add/operator/map';
import 'rxjs/add/observable/dom/ajax';
import 'rxjs/add/observable/from';
import 'rxjs/add/operator/catch';
import * as TakedownActions from '../actions/takedown';
import * as FileActions from '../actions/file';
import { File } from '../entities/file';

export function upload( action$, store ) {
	return action$
		.filter( ( action ) => {
			const types = [
				'FILE_ADD',
				'FILE_ADD_MULTIPLE'
			];

			return types.includes( action.type );
		} )
		.flatMap( ( action ) => {
			let files = [];

			switch ( action.type ) {
				case 'FILE_ADD':
					files = [ action.file ];
					break;
				case 'FILE_ADD_MULTIPLE':
					files = [ ...action.files ];
					break;
			}

			return Observable.from( files );
		} )
		.filter( ( file ) => file.status === 'local' )
		.flatMap( ( file ) => {
			const progressSubscriber = new Subject(),
				progress = progressSubscriber.map( ( event ) => {
					const percent = parseInt( ( event.loaded / event.total ) * 100 );
					file = file.set( 'progress', percent );
					return FileActions.update( file );
				} ),
				request = Observable.ajax( {
					url: '/api/file/' + encodeURIComponent( file.name ),
					method: 'POST',
					body: file.file,
					progressSubscriber: progressSubscriber,
					responseType: 'json',
					headers: {
						Authorization: 'Bearer ' + store.getState().token,
						'Content-Type': file.file.type
					}
				} )
					.flatMap( ( ajaxResponse ) => {
						const key = store.getState().takedown.create.dmca.fileIds.keyOf( file.id ),
							response = new File( ajaxResponse.response );

						let takedown = store.getState().takedown.create,
							fileIds = takedown.dmca.fileIds;

						if ( typeof key !== 'undefined' ) {
							takedown = takedown.setIn( [ 'dmca', 'fileIds' ], fileIds.set( key, response.id ) );
							return Observable.concat(
								Observable.of( FileActions.swap( file, response ) ),
								Observable.of( TakedownActions.updateCreate( takedown ) )
							);
						}

						return Observable.of( FileActions.swap( file, response ) );
					} )
					.takeUntil( action$.ofType( 'FILE_DELETE' ).filter( ( action ) => action.file.id === file.id ) );

			file = file.set( 'status', 'uploading' );
			return Observable.merge(
				Observable.of( FileActions.update( file ) ),
				progress,
				request
			).catch( ( ajaxError ) => {
				file = file.set( 'status', 'error' ).set( 'error', ajaxError.status );
				return Observable.of( FileActions.update( file ) );
			} );
		} );
}

export function deleteFile( action$, store ) {
	return action$.ofType( 'FILE_DELETE' )
		.filter( ( action ) => !isNaN( parseInt( action.file.id ) ) )
		.flatMap( ( action ) => {
			return Observable.ajax( {
				url: '/api/file/' + action.file.id,
				method: 'DELETE',
				headers: {
					Authorization: 'Bearer ' + store.getState().token
				}
			} )
				.map( () => {
					return {
						type: 'FILE_DELETE_COMPLETE',
						file: action.file
					};
				} )
				.catch( ( ajaxError ) => {
					return Observable.of( {
						type: 'FILE_DELETE_ERROR',
						error: ajaxError
					} );
				} );
		} );
}

export function fetchFiles( action$, store ) {
	return action$.filter( ( action ) => {
		const types = [
			'TAKEDOWN_ADD_MULTIPLE',
			'TAKEDOWN_ADD'
		];

		return types.includes( action.type );
	} )
		.flatMap( ( action ) => {
			let takedowns = [];

			// Get the takedowns that are being added.
			switch ( action.type ) {
				case 'TAKEDOWN_ADD':
					takedowns = [ action.takedown ];
					break;

				case 'TAKEDOWN_ADD_MULTIPLE':
					takedowns = [
						...action.takedowns
					];
					break;
			}

			return Observable.from( takedowns );
		} )
		.filter( ( takedown ) => !!takedown.dmca )
		.filter( ( takedown ) => takedown.dmca.fileIds.size > 0 )
		.flatMap( ( takedown ) => {
			return Observable.from( takedown.dmca.fileIds.reduce( ( fileIds, id ) => {
				return [
					...fileIds,
					id
				];
			}, [] ) );
		} )
		.distinct()
		.flatMap( ( id ) => {
			return Observable.ajax( {
				url: '/api/file/' + id + '?metadata',
				method: 'GET',
				responseType: 'json',
				headers: {
					Authorization: 'Bearer ' + store.getState().token
				}
			} )
				.map( ( ajaxResponse ) => {
					return FileActions.add( new File( ajaxResponse.response ) );
				} )
				.catch( ( ajaxError ) => {
					const file = new File( {
						id: id,
						status: 'error',
						error: ajaxError.status
					} );
					return Observable.of( FileActions.add( file ) );
				} );
		} );
}
