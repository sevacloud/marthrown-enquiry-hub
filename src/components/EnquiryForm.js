/**
 * EnquiryForm — one form serving both enquiry write paths.
 *
 * Manual creation and correction submit the same nine fields, so they are one
 * component rather than two: only the submit target and the initial values
 * differ (Requirements 18.23, 19.20). Passing no `enquiry` opens the form empty
 * and `POST`s to `/enquiries`; passing one pre-fills from its stored values and
 * `PATCH`es to `/enquiries/{id}`.
 *
 * Two rules the two modes do not share, and they are the two the API cares
 * about:
 *
 * - *Create* omits an optional field from the request body when it is left
 *   blank, rather than sending it empty. The Manual Validation Profile reads an
 *   absent optional field as not supplied and an empty one as a value that
 *   failed its rule, so sending `phone: ''` would be a different request from
 *   not sending `phone` at all (Requirements 18.4, 18.5).
 * - *Edit* submits only the fields the user actually altered, which is what
 *   keeps the request a genuine partial update: a field absent from the body is
 *   a field the store leaves alone (Requirement 19.7).
 *
 * Validation itself belongs to the server — one authority, applied to the
 * webhook and to these two routes alike — so the form marks `first_name`,
 * `last_name`, `email` and the first candidate date required, marks the other
 * five optional, and renders whatever a 400 names. The `errors` map is read
 * whole and rendered against each field it names, so a submission failing on
 * three fields reports all three in one pass instead of one per attempt.
 *
 * `status`, `source`, `is_test`, `booking_id` and the payload snapshot are
 * neither rendered nor submitted: none is a correctable detail, and the edit
 * route leaves all of them unchanged (Requirement 19.9).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import {
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { createEnquiry, updateEnquiry, fieldErrors } from '../api';

/**
 * The nine writable fields, with the labels the form and its error summary use.
 *
 * The order is the order they render in.
 */
const LABELS = {
	first_name: __( 'First name', 'marthrown-enquiry-hub' ),
	last_name: __( 'Last name', 'marthrown-enquiry-hub' ),
	email: __( 'Email', 'marthrown-enquiry-hub' ),
	phone: __( 'Phone', 'marthrown-enquiry-hub' ),
	total_guests: __( 'Total guests', 'marthrown-enquiry-hub' ),
	selected_dates: __( 'Candidate dates', 'marthrown-enquiry-hub' ),
	event_type: __( 'Event type', 'marthrown-enquiry-hub' ),
	site_exclusivity: __( 'Site exclusivity', 'marthrown-enquiry-hub' ),
	message: __( 'Message', 'marthrown-enquiry-hub' ),
};

/**
 * The two multi-value fields, entered as comma-separated lists.
 *
 * The permitted vocabularies come from a site filter the browser cannot read,
 * so the form collects what was typed and lets the server's vocabulary check
 * be the judge.
 */
const TAXONOMIES = [ 'event_type', 'site_exclusivity' ];

/**
 * Candidate date ceiling, matching the validator's own (Requirement 3.5).
 */
const MAX_DATES = 10;

/**
 * Readable text for the codes the `errors` map carries.
 *
 * An unrecognised code is rendered as it arrived rather than swallowed: a field
 * the server named must say something, even if this map has not caught up.
 */
const MESSAGES = {
	required: __( 'This field is required.', 'marthrown-enquiry-hub' ),
	empty: __( 'This field cannot be left blank.', 'marthrown-enquiry-hub' ),
	invalid_email: __(
		'Enter a valid email address.',
		'marthrown-enquiry-hub'
	),
	no_digits: __(
		'Enter a telephone number containing at least one digit.',
		'marthrown-enquiry-hub'
	),
	not_whole_number: __( 'Enter a whole number.', 'marthrown-enquiry-hub' ),
	out_of_range: __(
		'Enter a guest count from 1 to 10000.',
		'marthrown-enquiry-hub'
	),
	too_few_dates: __(
		'Choose at least one candidate date.',
		'marthrown-enquiry-hub'
	),
	too_many_dates: __(
		'Choose no more than ten candidate dates.',
		'marthrown-enquiry-hub'
	),
	unparseable_date: __(
		'One of the candidate dates could not be read.',
		'marthrown-enquiry-hub'
	),
	not_allowed: __(
		'One of the selected values is not permitted on this site.',
		'marthrown-enquiry-hub'
	),
};

export default function EnquiryForm( { enquiry = null, onSaved, onCancel } ) {
	const isEdit = !! ( enquiry && enquiry.id );
	const initial = useMemo( () => initialValues( enquiry ), [ enquiry ] );
	const [ values, setValues ] = useState( initial );
	const [ errors, setErrors ] = useState( {} );
	const [ failure, setFailure ] = useState( '' );
	const [ saving, setSaving ] = useState( false );

	const set = ( field ) => ( value ) =>
		setValues( ( prev ) => ( { ...prev, [ field ]: value } ) );

	const setDate = ( index ) => ( value ) =>
		setValues( ( prev ) => {
			const dates = [ ...prev.selected_dates ];
			dates[ index ] = value;
			return { ...prev, selected_dates: dates };
		} );

	const addDate = () =>
		setValues( ( prev ) => ( {
			...prev,
			selected_dates: [ ...prev.selected_dates, '' ],
		} ) );

	const removeDate = ( index ) =>
		setValues( ( prev ) => {
			const dates = prev.selected_dates.filter(
				( _date, i ) => i !== index
			);
			return {
				...prev,
				selected_dates: dates.length ? dates : [ '' ],
			};
		} );

	const onSubmit = async ( event ) => {
		event.preventDefault();

		if ( saving ) {
			return;
		}

		setSaving( true );
		setErrors( {} );
		setFailure( '' );

		const body = isEdit
			? alteredFields( values, initial )
			: createFields( values );

		try {
			const saved = isEdit
				? await updateEnquiry( enquiry.id, body )
				: await createEnquiry( body );

			if ( onSaved ) {
				onSaved( saved );
			}
		} catch ( error ) {
			const named = fieldErrors( error );
			setErrors( named );
			setFailure( failureNotice( error, named ) );
		} finally {
			setSaving( false );
		}
	};

	// Fields the server named that this form does not render — nothing should
	// reach the user as an unexplained refusal.
	const unnamed = Object.keys( errors ).filter( ( f ) => ! LABELS[ f ] );

	// `noValidate` leaves the required marks as marks: the browser does not
	// block the submission, so a blank required field is answered by the same
	// server-side 400 as every other failure, alongside whatever else failed,
	// instead of the first blank field alone stopping the form.
	return (
		<form className="meh-form" onSubmit={ onSubmit } noValidate>
			<h3 className="meh-form__title">
				{ isEdit
					? __( 'Edit enquiry', 'marthrown-enquiry-hub' )
					: __( 'New enquiry', 'marthrown-enquiry-hub' ) }
			</h3>

			{ failure && (
				<Notice status="error" isDismissible={ false }>
					{ failure }
					{ unnamed.length > 0 && (
						<ul>
							{ unnamed.map( ( field ) => (
								<li key={ field }>
									{ field }: { message( errors[ field ] ) }
								</li>
							) ) }
						</ul>
					) }
				</Notice>
			) }

			<TextControl
				label={ LABELS.first_name }
				value={ values.first_name }
				onChange={ set( 'first_name' ) }
				required
				className="meh-form__field"
				__nextHasNoMarginBottom
			/>
			<FieldError code={ errors.first_name } />

			<TextControl
				label={ LABELS.last_name }
				value={ values.last_name }
				onChange={ set( 'last_name' ) }
				required
				className="meh-form__field"
				__nextHasNoMarginBottom
			/>
			<FieldError code={ errors.last_name } />

			<TextControl
				label={ LABELS.email }
				type="email"
				value={ values.email }
				onChange={ set( 'email' ) }
				required
				className="meh-form__field"
				__nextHasNoMarginBottom
			/>
			<FieldError code={ errors.email } />

			<fieldset className="meh-form__dates">
				<legend>
					{ LABELS.selected_dates }{ ' ' }
					<span className="meh-form__required">
						{ __( '(required)', 'marthrown-enquiry-hub' ) }
					</span>
				</legend>

				{ values.selected_dates.map( ( date, index ) => (
					// The row identity is the position, because two rows can
					// legitimately hold the same value while one is being typed.
					// eslint-disable-next-line react/no-array-index-key
					<div className="meh-form__date" key={ index }>
						<label className="meh-date-label">
							{ sprintf(
								/* translators: %d: candidate date position. */
								__( 'Date %d', 'marthrown-enquiry-hub' ),
								index + 1
							) }
							<input
								type="date"
								value={ date }
								required={ 0 === index }
								onChange={ ( e ) =>
									setDate( index )( e.target.value )
								}
							/>
						</label>
						{ values.selected_dates.length > 1 && (
							<button
								type="button"
								className="button-link"
								onClick={ () => removeDate( index ) }
							>
								{ __( 'Remove', 'marthrown-enquiry-hub' ) }
							</button>
						) }
					</div>
				) ) }

				{ values.selected_dates.length < MAX_DATES && (
					<button
						type="button"
						className="button"
						onClick={ addDate }
					>
						{ __( 'Add another date', 'marthrown-enquiry-hub' ) }
					</button>
				) }

				<FieldError code={ errors.selected_dates } />
			</fieldset>

			<TextControl
				label={ LABELS.phone }
				value={ values.phone }
				onChange={ set( 'phone' ) }
				help={ optionalHint() }
				className="meh-form__field"
				__nextHasNoMarginBottom
			/>
			<FieldError code={ errors.phone } />

			<TextControl
				label={ LABELS.total_guests }
				value={ values.total_guests }
				onChange={ set( 'total_guests' ) }
				help={ optionalHint(
					__( 'A whole number from 1 to 10000.', 'marthrown-enquiry-hub' )
				) }
				className="meh-form__field"
				__nextHasNoMarginBottom
			/>
			<FieldError code={ errors.total_guests } />

			<TextControl
				label={ LABELS.event_type }
				value={ values.event_type }
				onChange={ set( 'event_type' ) }
				help={ optionalHint( listHint() ) }
				className="meh-form__field"
				__nextHasNoMarginBottom
			/>
			<FieldError code={ errors.event_type } />

			<TextControl
				label={ LABELS.site_exclusivity }
				value={ values.site_exclusivity }
				onChange={ set( 'site_exclusivity' ) }
				help={ optionalHint( listHint() ) }
				className="meh-form__field"
				__nextHasNoMarginBottom
			/>
			<FieldError code={ errors.site_exclusivity } />

			<TextareaControl
				label={ LABELS.message }
				value={ values.message }
				onChange={ set( 'message' ) }
				help={ optionalHint() }
				className="meh-form__field"
				__nextHasNoMarginBottom
			/>
			<FieldError code={ errors.message } />

			<div className="meh-form__actions">
				<button
					type="submit"
					className="button button-primary"
					disabled={ saving }
				>
					{ isEdit
						? __( 'Save changes', 'marthrown-enquiry-hub' )
						: __( 'Create enquiry', 'marthrown-enquiry-hub' ) }
				</button>
				{ onCancel && (
					<button
						type="button"
						className="button"
						onClick={ onCancel }
						disabled={ saving }
					>
						{ __( 'Cancel', 'marthrown-enquiry-hub' ) }
					</button>
				) }
				{ saving && <Spinner /> }
			</div>
		</form>
	);
}

/**
 * One field's server-side message, or nothing.
 *
 * @param {Object} props      Component props.
 * @param {string} props.code Error code the `errors` map held for the field.
 * @return {JSX.Element|null} The rendered message.
 */
function FieldError( { code } ) {
	if ( ! code ) {
		return null;
	}

	return (
		<p className="meh-form__error" role="alert">
			{ message( code ) }
		</p>
	);
}

/**
 * The form state a mode starts from.
 *
 * Create starts empty with one blank date row; edit starts from the displayed
 * enquiry's stored values, which is also the baseline the altered-field
 * comparison measures against.
 *
 * @param {Object|null} enquiry Enquiry being corrected, or null when creating.
 * @return {Object} Form state.
 */
function initialValues( enquiry ) {
	const stored = enquiry || {};
	const dates = list( stored.selected_dates );

	return {
		first_name: text( stored.first_name ),
		last_name: text( stored.last_name ),
		email: text( stored.email ),
		phone: text( stored.phone ),
		total_guests: text( stored.total_guests ),
		selected_dates: dates.length ? dates : [ '' ],
		event_type: list( stored.event_type ).join( ', ' ),
		site_exclusivity: list( stored.site_exclusivity ).join( ', ' ),
		message: text( stored.message ),
	};
}

/**
 * The request body manual creation submits.
 *
 * The four required fields are always present, so a blank one earns a 400
 * naming it rather than passing unnoticed. An optional field left blank is
 * omitted entirely, which is what the Manual Validation Profile reads as "not
 * supplied" (Requirements 18.4, 18.5).
 *
 * @param {Object} values Form state.
 * @return {Object} Request body.
 */
function createFields( values ) {
	const body = {
		first_name: submitted( values, 'first_name' ),
		last_name: submitted( values, 'last_name' ),
		email: submitted( values, 'email' ),
		selected_dates: submitted( values, 'selected_dates' ),
	};

	[ 'phone', 'total_guests', 'message', ...TAXONOMIES ].forEach( ( field ) => {
		const value = submitted( values, field );

		if ( ! blank( value ) ) {
			body[ field ] = value;
		}
	} );

	return body;
}

/**
 * The request body a correction submits: the altered fields and nothing else.
 *
 * A field the user did not touch is absent, so the store leaves its stored
 * value alone (Requirement 19.7). A field the user cleared is present and
 * empty, because clearing an optional value is itself an alteration — the
 * server decides whether that is allowed, which for the four required fields it
 * is not (Requirement 19.4).
 *
 * @param {Object} values  Form state.
 * @param {Object} initial The state the form opened with.
 * @return {Object} Request body.
 */
function alteredFields( values, initial ) {
	const body = {};

	Object.keys( LABELS ).forEach( ( field ) => {
		const value = submitted( values, field );

		if ( ! same( value, submitted( initial, field ) ) ) {
			body[ field ] = value;
		}
	} );

	return body;
}

/**
 * One field's submittable value, read out of the form state.
 *
 * Every field is normalised the same way in both modes, so "did the user alter
 * this?" and "what do we send?" can never answer from different values.
 *
 * @param {Object} values Form state.
 * @param {string} field  Field name.
 * @return {string|string[]} Submittable value.
 */
function submitted( values, field ) {
	if ( 'selected_dates' === field ) {
		return values.selected_dates
			.map( ( date ) => date.trim() )
			.filter( ( date ) => '' !== date );
	}

	if ( TAXONOMIES.includes( field ) ) {
		return values[ field ]
			.split( ',' )
			.map( ( term ) => term.trim() )
			.filter( ( term ) => '' !== term );
	}

	return values[ field ].trim();
}

/**
 * Whether two submittable values are the same value.
 *
 * @param {string|string[]} a First value.
 * @param {string|string[]} b Second value.
 * @return {boolean} True when equal.
 */
function same( a, b ) {
	if ( Array.isArray( a ) || Array.isArray( b ) ) {
		return list( a ).join( '\n' ) === list( b ).join( '\n' );
	}

	return a === b;
}

/**
 * Whether a submittable value carries nothing.
 *
 * @param {string|string[]} value Submittable value.
 * @return {boolean} True when empty.
 */
function blank( value ) {
	return Array.isArray( value ) ? 0 === value.length : '' === value;
}

/**
 * A stored value as form text. `null` guest counts and absent fields alike
 * become the empty string, which is what an untouched input holds.
 *
 * @param {*} value Stored value.
 * @return {string} Form text.
 */
function text( value ) {
	return value === null || value === undefined ? '' : String( value );
}

/**
 * A stored value as a list of strings.
 *
 * @param {*} value Stored value.
 * @return {string[]} List.
 */
function list( value ) {
	return Array.isArray( value ) ? value.map( ( item ) => text( item ) ) : [];
}

/**
 * The message a code stands for, falling back to the code itself.
 *
 * @param {string} code Error code.
 * @return {string} Message.
 */
function message( code ) {
	return MESSAGES[ code ] || String( code );
}

/**
 * The summary a rejection earns.
 *
 * A validation failure already reports itself field by field, so the summary
 * only says where to look. Anything else — 409 on a closed enquiry, 404, a
 * transport failure — has nothing to attach to a field, so its own message is
 * the whole of what the user gets.
 *
 * @param {*}      error Rejection from the write call.
 * @param {Object} named The per-field map it carried.
 * @return {string} Notice text.
 */
function failureNotice( error, named ) {
	if ( Object.keys( named ).length > 0 ) {
		return __(
			'Some of the details need correcting.',
			'marthrown-enquiry-hub'
		);
	}

	return (
		( error && error.message ) ||
		__( 'The enquiry could not be saved.', 'marthrown-enquiry-hub' )
	);
}

/**
 * Help text marking a field optional, with an optional format note appended.
 *
 * @param {string} note Extra guidance.
 * @return {string} Help text.
 */
function optionalHint( note = '' ) {
	const optional = __( 'Optional.', 'marthrown-enquiry-hub' );

	return note ? `${ optional } ${ note }` : optional;
}

/**
 * The note the two comma-separated fields carry.
 *
 * @return {string} Help text.
 */
function listHint() {
	return __( 'Separate values with commas.', 'marthrown-enquiry-hub' );
}
