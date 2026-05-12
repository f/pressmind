import {
	createBlock,
	parse,
	registerBlockType,
	serialize,
} from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import {
	Button,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

import Edit from './edit';
import metadata from './block.json';
import './editor.scss';
import save from './save';

const stripHtml = ( value = '' ) => {
	const element = document.createElement( 'div' );
	element.innerHTML = value;

	return ( element.textContent || element.innerText || '' ).trim();
};

const textFromAttributes = ( attributes = {} ) =>
	stripHtml(
		attributes.content ||
			attributes.value ||
			attributes.values ||
			attributes.citation ||
			''
	);

const createPromptBlockFromText = ( attributes ) =>
	createBlock( metadata.name, {
		prompt: textFromAttributes( attributes ),
	} );

registerBlockType( metadata.name, {
	edit: Edit,
	save,
	transforms: {
		from: [
			{
				type: 'block',
				blocks: [
					'core/paragraph',
					'core/heading',
					'core/quote',
					'core/list',
					'core/preformatted',
					'core/code',
					'core/verse',
				],
				transform: createPromptBlockFromText,
			},
		],
	},
} );

const buildSandboxSrcDoc = ( { html = '', css = '', js = '' }, sandboxId ) => {
	const safeJs = js.replace( /<\/script/gi, '<\\/script' );
	const resizeScript = `
		(function () {
			var sandboxId = ${ JSON.stringify( sandboxId ) };
			var lastHeight = 0;
			function measure() {
				var body = document.body;
				var html = document.documentElement;
				var height = Math.ceil(Math.max(
					body ? body.scrollHeight : 0,
					body ? body.offsetHeight : 0,
					html ? html.scrollHeight : 0,
					html ? html.offsetHeight : 0
				));
				if (height && Math.abs(height - lastHeight) > 1) {
					lastHeight = height;
					parent.postMessage({ type: 'pressmind:sandbox:resize', id: sandboxId, height: height }, '*');
				}
			}
			window.addEventListener('load', measure);
			window.addEventListener('resize', measure);
			if (window.ResizeObserver) {
				new ResizeObserver(measure).observe(document.documentElement);
				if (document.body) {
					new ResizeObserver(measure).observe(document.body);
				}
			}
			if (window.MutationObserver) {
				new MutationObserver(measure).observe(document.documentElement, {
					childList: true,
					subtree: true,
					attributes: true,
					characterData: true
				});
			}
			document.addEventListener('load', function (event) {
				if (event.target && event.target.tagName === 'IMG') {
					measure();
				}
			}, true);
			setTimeout(measure, 0);
			setTimeout(measure, 100);
			setTimeout(measure, 500);
		})();`.replace( /<\/script/gi, '<\\/script' );

	return `<!doctype html>
<html>
	<head>
		<meta charset="utf-8" />
		<meta name="viewport" content="width=device-width,initial-scale=1" />
		<style>
			html,body{margin:0;padding:0;box-sizing:border-box;overflow:hidden;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;}
			*,*:before,*:after{box-sizing:inherit;}
			${ css }
		</style>
	</head>
	<body>
		${ html }
		<script>${ resizeScript }</script>
		<script>${ safeJs }</script>
	</body>
</html>`;
};

function SandboxEdit( { attributes, setAttributes, isSelected, clientId } ) {
	const blockProps = useBlockProps( {
		className: 'pressmind-sandbox-block',
	} );
	const iframeRef = useRef();
	const sandboxId = `pressmind-sandbox-${ clientId }`;
	const minHeight = Math.max( 240, Number( attributes.height ) || 640 );
	const [ iframeHeight, setIframeHeight ] = useState( minHeight );

	useEffect( () => {
		setIframeHeight( minHeight );
	}, [ minHeight, attributes.html, attributes.css, attributes.js ] );

	useEffect( () => {
		const handleMessage = ( event ) => {
			if ( event.source !== iframeRef.current?.contentWindow ) {
				return;
			}

			const data = event.data || {};

			if (
				data.type !== 'pressmind:sandbox:resize' ||
				data.id !== sandboxId
			) {
				return;
			}

			const nextHeight = Math.max(
				minHeight,
				Number( data.height ) || 0
			);

			setIframeHeight( nextHeight );
		};

		window.addEventListener( 'message', handleMessage );

		return () => window.removeEventListener( 'message', handleMessage );
	}, [ minHeight, sandboxId ] );

	return (
		<div { ...blockProps }>
			{ isSelected ? (
				<div className="pressmind-sandbox-block__editor">
					<TextControl
						label={ __( 'Title', 'pressmind' ) }
						value={ attributes.title }
						onChange={ ( title ) => setAttributes( { title } ) }
					/>
					<TextControl
						label={ __( 'Height', 'pressmind' ) }
						type="number"
						value={ attributes.height }
						onChange={ ( nextHeight ) =>
							setAttributes( {
								height: Number( nextHeight ) || 640,
							} )
						}
					/>
					<TextareaControl
						label={ __( 'HTML', 'pressmind' ) }
						value={ attributes.html }
						rows={ 6 }
						onChange={ ( html ) => setAttributes( { html } ) }
					/>
					<TextareaControl
						label={ __( 'CSS', 'pressmind' ) }
						value={ attributes.css }
						rows={ 6 }
						onChange={ ( css ) => setAttributes( { css } ) }
					/>
					<TextareaControl
						label={ __( 'JavaScript', 'pressmind' ) }
						value={ attributes.js }
						rows={ 6 }
						onChange={ ( js ) => setAttributes( { js } ) }
					/>
				</div>
			) : null }
			<iframe
				ref={ iframeRef }
				title={ attributes.title }
				sandbox="allow-scripts"
				referrerPolicy="no-referrer"
				scrolling="no"
				srcDoc={ buildSandboxSrcDoc( attributes, sandboxId ) }
				style={ {
					background: '#fff',
					border: '1px solid #ddd',
					borderRadius: '4px',
					display: 'block',
					height: iframeHeight,
					overflow: 'hidden',
					width: '100%',
				} }
			/>
		</div>
	);
}

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

function AiEditPanel( { blockName, clientId, attributes } ) {
	const [ prompt, setPrompt ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ streamText, setStreamText ] = useState( '' );
	const [ isGenerating, setIsGenerating ] = useState( false );
	const { replaceBlocks } = useDispatch( 'core/block-editor' );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( 'core/notices' );
	const block = useSelect(
		( select ) => select( 'core/block-editor' ).getBlock( clientId ),
		[ clientId ]
	);
	const postContext = useSelect( ( select ) => {
		const editor = select( 'core/editor' );

		return {
			postId: editor.getCurrentPostId?.(),
			postType: editor.getCurrentPostType?.(),
			title: editor.getEditedPostAttribute( 'title' ),
			content: editor.getEditedPostAttribute( 'content' ),
		};
	}, [] );

	const serializedBlock = block ? serialize( [ block ] ) : '';
	const existingCode =
		blockName === 'pressmind/sandbox'
			? JSON.stringify(
					{
						title: attributes.title,
						height: attributes.height,
						html: attributes.html,
						css: attributes.css,
						js: attributes.js,
					},
					null,
					2
			  )
			: attributes.content || serializedBlock;

	const refineBlock = async () => {
		const trimmedPrompt = prompt.trim();

		if ( ! trimmedPrompt ) {
			setError( __( 'Enter an edit prompt first.', 'pressmind' ) );
			return;
		}

		setIsGenerating( true );
		setError( '' );
		setStreamText( '' );

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
						prompt: `${ trimmedPrompt }\n\nEdit the existing ${ blockName } block below. Return the complete updated replacement block, not just a diff.`,
						postId: postContext.postId,
						context: {
							...postContext,
							editMode: true,
							existingBlock: {
								name: blockName,
								serialized: serializedBlock,
								code: existingCode,
							},
						},
					} ),
				}
			);

			if ( ! response.ok || ! response.body?.getReader ) {
				throw new Error(
					__( 'The AI edit request failed.', 'pressmind' )
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

				if ( parsedEvent.event === 'token' ) {
					setStreamText(
						( current ) =>
							current + ( parsedEvent.data.token || '' )
					);
				}

				if ( parsedEvent.event === 'error' ) {
					throw new Error( parsedEvent.data.message );
				}

				if ( parsedEvent.event === 'final' ) {
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
				rawEvents.forEach( handleRawEvent );
			}

			if ( buffer.trim() ) {
				handleRawEvent( buffer );
			}

			if ( ! finalResponse ) {
				throw new Error(
					__( 'The AI edit did not return blocks.', 'pressmind' )
				);
			}

			const parsedBlocks = defaultHtmlBlocksToPreview(
				parse( finalResponse.serializedBlocks || '' )
			);

			if ( ! parsedBlocks.length ) {
				throw new Error(
					__(
						'The AI edit returned no insertable blocks.',
						'pressmind'
					)
				);
			}

			replaceBlocks( clientId, parsedBlocks );
			createSuccessNotice( __( 'Block updated with AI.', 'pressmind' ), {
				type: 'snackbar',
			} );
		} catch ( apiError ) {
			const message =
				apiError?.message || __( 'The AI edit failed.', 'pressmind' );

			setError( message );
			createErrorNotice( message, { type: 'snackbar' } );
		} finally {
			setIsGenerating( false );
		}
	};

	return (
		<div className="pressmind-ai-edit-panel">
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
				label={ __( 'Edit this block with AI', 'pressmind' ) }
				help={ __(
					'The current block code is sent as context.',
					'pressmind'
				) }
				value={ prompt }
				rows={ 3 }
				onChange={ setPrompt }
				disabled={ isGenerating }
			/>
			<Button
				variant="secondary"
				onClick={ refineBlock }
				disabled={ isGenerating || ! prompt.trim() }
			>
				{ isGenerating
					? __( 'Editing…', 'pressmind' )
					: __( 'Update Block with AI', 'pressmind' ) }
			</Button>
			{ isGenerating ? <Spinner /> : null }
			{ streamText ? (
				<pre className="pressmind-prompt-block__stream">
					{ streamText }
				</pre>
			) : null }
		</div>
	);
}

addFilter(
	'editor.BlockEdit',
	'pressmind/ai-edit-existing-block',
	( BlockEdit ) => ( props ) => {
		const isEditableGeneratedBlock =
			props.isSelected &&
			( props.name === 'core/html' ||
				props.name === 'pressmind/sandbox' );

		return (
			<>
				<BlockEdit { ...props } />
				{ isEditableGeneratedBlock ? (
					<AiEditPanel
						blockName={ props.name }
						clientId={ props.clientId }
						attributes={ props.attributes }
					/>
				) : null }
			</>
		);
	}
);

registerBlockType( 'pressmind/sandbox', {
	apiVersion: 3,
	title: __( 'AI Sandboxed Content', 'pressmind' ),
	category: 'widgets',
	icon: 'editor-code',
	attributes: {
		title: {
			type: 'string',
			default: __( 'AI generated interactive content', 'pressmind' ),
		},
		html: {
			type: 'string',
			default: '',
		},
		css: {
			type: 'string',
			default: '',
		},
		js: {
			type: 'string',
			default: '',
		},
		height: {
			type: 'number',
			default: 640,
		},
	},
	supports: {
		html: false,
	},
	edit: SandboxEdit,
	save() {
		return null;
	},
} );
