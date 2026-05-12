import { useBlockProps } from '@wordpress/block-editor';
import { parse, serialize } from '@wordpress/blocks';
import {
	Button,
	Notice,
	Placeholder,
	Spinner,
	TextareaControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const normalizeEditorValue = ( value ) => {
	if ( typeof value === 'string' ) {
		return value;
	}

	if ( value?.raw ) {
		return value.raw;
	}

	if ( value?.rendered ) {
		return value.rendered;
	}

	return '';
};

const defaultHtmlBlocksToPreview = ( blocks ) =>
	blocks.map( ( block ) => ( {
		...block,
		attributes:
			block.name === 'core/html'
				? { ...block.attributes, preview: true }
				: block.attributes,
		innerBlocks: block.innerBlocks?.length
			? defaultHtmlBlocksToPreview( block.innerBlocks )
			: block.innerBlocks,
	} ) );

const getRestUrl = ( path ) => {
	const root = window.wpApiSettings?.root || '/wp-json/';

	return `${ root.replace( /\/$/, '' ) }/${ path.replace( /^\//, '' ) }`;
};

const getRestNonce = () => window.wpApiSettings?.nonce || '';

const parseServerSentEvent = ( rawEvent ) => {
	const lines = rawEvent.split( /\r?\n/ );
	let event = 'message';
	const dataLines = [];

	lines.forEach( ( line ) => {
		if ( line.startsWith( 'event:' ) ) {
			event = line.slice( 6 ).trim();
		}

		if ( line.startsWith( 'data:' ) ) {
			dataLines.push( line.slice( 5 ).trimStart() );
		}
	} );

	if ( ! dataLines.length ) {
		return null;
	}

	return {
		event,
		data: JSON.parse( dataLines.join( '\n' ) ),
	};
};

export default function Edit( { clientId } ) {
	const blockProps = useBlockProps( {
		className: 'pressmind-prompt-block',
	} );

	const [ prompt, setPrompt ] = useState( '' );
	const [ streamText, setStreamText ] = useState( '' );
	const [ streamStatus, setStreamStatus ] = useState( '' );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const [ error, setError ] = useState( '' );

	const editorContext = useSelect(
		( select ) => {
			const editor = select( 'core/editor' );
			const blockEditor = select( 'core/block-editor' );
			const selectedClientIds =
				blockEditor.getSelectedBlockClientIds?.() || [];
			const selectedBlocks = selectedClientIds
				.map( ( selectedClientId ) =>
					blockEditor.getBlock( selectedClientId )
				)
				.filter( Boolean );
			const blocks = blockEditor.getBlocks();
			const rootClientId = blockEditor.getBlockRootClientId( clientId );
			const blockIndex = blockEditor.getBlockIndex(
				clientId,
				rootClientId
			);

			return {
				postId: editor.getCurrentPostId?.(),
				postType: editor.getCurrentPostType?.(),
				title: normalizeEditorValue(
					editor.getEditedPostAttribute( 'title' )
				),
				excerpt: normalizeEditorValue(
					editor.getEditedPostAttribute( 'excerpt' )
				),
				content: normalizeEditorValue(
					editor.getEditedPostAttribute( 'content' )
				),
				selectedBlocks: selectedBlocks.length
					? serialize( selectedBlocks )
					: '',
				allBlocks: serialize( blocks ),
				rootClientId,
				insertionIndex: blockIndex,
			};
		},
		[ clientId ]
	);

	const { replaceBlocks } = useDispatch( 'core/block-editor' );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( 'core/notices' );

	const generateBlocks = async () => {
		const trimmedPrompt = prompt.trim();

		if ( ! trimmedPrompt ) {
			setError( __( 'Enter a prompt first.', 'pressmind' ) );
			return;
		}

		setIsGenerating( true );
		setError( '' );
		setStreamText( '' );
		setStreamStatus( __( 'Connecting to AI stream…', 'pressmind' ) );

		try {
			const response = await fetch(
				getRestUrl( '/pressmind/v1/generate-stream' ),
				{
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': getRestNonce(),
					},
					body: JSON.stringify( {
						prompt: trimmedPrompt,
						postId: editorContext.postId,
						context: {
							postType: editorContext.postType,
							title: editorContext.title,
							excerpt: editorContext.excerpt,
							content: editorContext.content,
							selectedBlocks: editorContext.selectedBlocks,
							allBlocks: editorContext.allBlocks,
						},
					} ),
				}
			);

			if ( ! response.ok ) {
				throw new Error(
					__(
						'The streaming request failed before generation started.',
						'pressmind'
					)
				);
			}

			if ( ! response.body?.getReader ) {
				throw new Error(
					__(
						'This browser does not support streaming responses.',
						'pressmind'
					)
				);
			}

			const reader = response.body.getReader();
			const decoder = new window.TextDecoder();
			let buffer = '';
			let finalResponse = null;

			const handleRawEvent = ( rawEvent ) => {
				const parsedEvent = parseServerSentEvent( rawEvent );

				if ( ! parsedEvent ) {
					return;
				}

				if ( parsedEvent.event === 'start' ) {
					setStreamStatus(
						parsedEvent.data.message ||
							__(
								'Connected. Waiting for AI tokens…',
								'pressmind'
							)
					);
				}

				if ( parsedEvent.event === 'token' ) {
					setStreamStatus(
						__( 'Streaming AI tokens…', 'pressmind' )
					);
					setStreamText(
						( current ) =>
							current + ( parsedEvent.data.token || '' )
					);
				}

				if ( parsedEvent.event === 'error' ) {
					throw new Error( parsedEvent.data.message );
				}

				if ( parsedEvent.event === 'final' ) {
					setStreamStatus(
						__( 'Rendering generated blocks…', 'pressmind' )
					);
					finalResponse = parsedEvent.data;
				}
			};

			while ( true ) {
				const { value, done } = await reader.read();

				if ( done ) {
					break;
				}

				buffer += decoder.decode( value, { stream: true } );

				const rawEvents = buffer.split( /\r?\n\r?\n/ );
				buffer = rawEvents.pop() || '';

				for ( const rawEvent of rawEvents ) {
					handleRawEvent( rawEvent );
				}
			}

			if ( buffer.trim() ) {
				handleRawEvent( buffer );
			}

			if ( ! finalResponse ) {
				throw new Error(
					__(
						'The AI stream ended without final blocks.',
						'pressmind'
					)
				);
			}

			const parsedBlocks = defaultHtmlBlocksToPreview(
				parse( finalResponse.serializedBlocks || '' )
			);

			if ( ! parsedBlocks.length ) {
				throw new Error(
					__(
						'The AI response did not contain insertable blocks.',
						'pressmind'
					)
				);
			}

			replaceBlocks( clientId, parsedBlocks );
			createSuccessNotice(
				finalResponse.summary ||
					__( 'AI-generated blocks inserted.', 'pressmind' ),
				{ type: 'snackbar' }
			);
		} catch ( apiError ) {
			const message =
				apiError?.message ||
				__(
					'The AI request failed. Check your provider settings and try again.',
					'pressmind'
				);

			setError( message );
			createErrorNotice( message, { type: 'snackbar' } );
		} finally {
			setIsGenerating( false );
			setStreamStatus( '' );
		}
	};

	return (
		<div { ...blockProps }>
			<Placeholder
				icon="superhero"
				label={ __( 'Pressmind', 'pressmind' ) }
				instructions={ __(
					'Describe the block or section you want. The current post context will be sent securely through WordPress.',
					'pressmind'
				) }
			>
				<div className="pressmind-prompt-block__body">
					{ error ? (
						<Notice
							status="error"
							isDismissible
							onRemove={ () => setError( '' ) }
						>
							{ error }
						</Notice>
					) : null }

					<TextareaControl
						label={ __( 'Prompt', 'pressmind' ) }
						value={ prompt }
						onChange={ setPrompt }
						rows={ 5 }
						placeholder={ __(
							'Example: Create an accessible SVG org chart from the teams described in this post.',
							'pressmind'
						) }
						disabled={ isGenerating }
					/>

					<div className="pressmind-prompt-block__actions">
						<Button
							variant="primary"
							onClick={ generateBlocks }
							disabled={ isGenerating || ! prompt.trim() }
						>
							{ isGenerating
								? __( 'Generating…', 'pressmind' )
								: __( 'Generate Blocks', 'pressmind' ) }
						</Button>
						{ isGenerating ? <Spinner /> : null }
					</div>

					{ isGenerating ? (
						<div className="pressmind-prompt-block__stream-wrap">
							<p className="pressmind-prompt-block__stream-status">
								{ streamStatus }
							</p>
							<pre className="pressmind-prompt-block__stream">
								{ streamText ||
									__(
										'Waiting for the first token…',
										'pressmind'
									) }
							</pre>
						</div>
					) : null }
				</div>
			</Placeholder>
		</div>
	);
}
