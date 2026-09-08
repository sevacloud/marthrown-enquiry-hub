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
 * `last_name`, `email` and the ideal start date required, marks the other five
 * optional, and renders whatever a 400 names. The `errors` map is read whole
 * and rendered against each field it names, so a submission failing on three
 * fields reports all three in one pass instead of one per attempt.
 *
 * Candidate dates are *ranges*, ranked. The form renders three start/end pairs
 * in a fixed order: the first is the ideal range and is required, the second
 * and third are optional alternatives the enquirer would settle for. That rank
 * is the whole point of the ordering — the store keeps the ranges in the order
 * they are sent — so the slots are never reordered or sorted by date. An end
 * date left blank is read as "just the one day", the start date alone, because
 * a single day is a range whose bounds match. A slot left entirely blank is not
 * sent at all, so filling the first and third slots submits two ranges rather
 * than three with a hole in the middle.
 *
 * `status`, `source`, `is_test`, `booking_id` and the payload snapshot are
 * neither rendered nor submitted: none is a correctable detail, and the edit
 * route leaves all of them unchanged (Requirement 19.9).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import {
	Notice,
	SelectControl,
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
	date_ranges: __( 'Candidate dates', 'marthrown-enquiry-hub' ),
	event_type: __( 'Event type', 'marthrown-enquiry-hub' ),
	site_exclusivity: __( 'Site exclusivity', 'marthrown-enquiry-hub' ),
	message: __( 'Message', 'marthrown-enquiry-hub' ),
};

/**
 * The two taxonomy fields, each a dropdown of exactly one value.
 *
 * The store and the Validator both still hold `event_type` and
 * `site_exclusivity` as term *sets* — 0 to 20 rows each — because a webhook
 * submission can still carry several. This form narrows what a person can
 * choose to one, which is what "a dropdown" means: `submitted()` wraps the
 * chosen value in a single-element array, or an empty array when nothing is
 * chosen. Editing an enquiry that already holds more than one stored term
 * shows only the first of them, and saving the form then replaces the whole
 * set with that one value — a real narrowing, not a display quirk.
 *
 * The vocabulary itself comes from `mehData`, which `AdminPage::enqueue_app()`
 * populates from `Validator::allowed_terms()` — the same read the server does
 * — so the dropdown can never offer a value the server would refuse.
 */
const TAXONOMIES = [ 'event_type', 'site_exclusivity' ];

/**
 * The configured vocabulary for one taxonomy dropdown.
 *
 * @param {string} field 'event_type' | 'site_exclusivity'.
 * @return {string[]} Permitted values; empty when nothing is configured.
 */
function vocabularyFor( field ) {
	const data = window.mehData || {};
	const key = 'event_type' === field ? 'eventTypes' : 'siteExclusivity';
	const values = data[ key ];

	return Array.isArray( values ) ? values : [];
}

/**
 * The candidate ranges the form collects: one ideal, two alternatives.
 *
 * The count matches the Validator's `MAX_RANGES`, and the slots are rendered
 * rather than added, so the form cannot offer a fourth. Where the two agree
 * there is nothing to enforce here; where an enquiry stored before this cap
 * existed carries more, `initialValues()` shows the first three (see there).
 */
const RANGE_SLOTS = [
	{
		legend: __( 'Ideal dates', 'marthrown-enquiry-hub' ),
		required: true,
	},
	{
		legend: __( 'Alternative dates', 'marthrown-enquiry-hub' ),
		required: false,
	},
	{
		legend: __( 'Second alternative dates', 'marthrown-enquiry-hub' ),
		required: false,
	},
];

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
	too_few_ranges: __(
		'Give the ideal dates.',
		'marthrown-enquiry-hub'
	),
	too_many_ranges: __(
		'Give no more than three date ranges.',
		'marthrown-enquiry-hub'
	),
	incomplete_range: __(
		'Give both a start and an end date for each range.',
		'marthrown-enquiry-hub'
	),
	ends_before_start: __(
		'A range cannot end before it starts.',
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
	const eventTypeOptions = useMemo(
		() => vocabularyFor( 'event_type' ),
		[]
	);
	const siteExclusivityOptions = useMemo(
		() => vocabularyFor( 'site_exclusivity' ),
		[]
	);
	const [ errors, setErrors ] = useState( {} );
	const [ failure, setFailure ] = useState( '' );
	const [ saving, setSaving ] = useState( false );

	const set = ( field ) => ( value ) =>
		setValues( ( prev ) => ( { ...prev, [ field ]: value } ) );

	// One bound of one range slot. The slots are replaced as a new array rather
	// than mutated in place, so React sees the change.
	const setBound = ( index, bound, value ) =>
		setValues( ( prev ) => ( {
			...prev,
			ranges: prev.ranges.map( ( range, at ) =>
				at === index ? { ...range, [ bound ]: value } : range
			),
		} ) );

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
				<legend>{ LABELS.date_ranges }</legend>

				{ RANGE_SLOTS.map( ( slot, index ) => (
					<div className="meh-form__range" key={ index }>
						<span className="meh-form__range-legend">
							{ slot.legend }{ ' ' }
							<span className="meh-form__required">
								{ slot.required
									? __(
											'(required)',
											'marthrown-enquiry-hub'
									  )
									: __(
											'(optional)',
											'marthrown-enquiry-hub'
									  ) }
							</span>
						</span>

						<div className="meh-form__date">
							<label className="meh-date-label">
								{ __(
									'Start date',
									'marthrown-enquiry-hub'
								) }
								<input
									type="date"
									value={ values.ranges[ index ].start }
									required={ slot.required }
									onChange={ ( e ) =>
										setBound(
											index,
											'start',
											e.target.value
										)
									}
								/>
							</label>
						</div>

						<div className="meh-form__date">
							<label className="meh-date-label">
								{ __( 'End date', 'marthrown-enquiry-hub' ) }
								<input
									type="date"
									value={ values.ranges[ index ].end }
									onChange={ ( e ) =>
										setBound( index, 'end', e.target.value )
									}
								/>
							</label>
						</div>
					</div>
				) ) }

				<p className="description">
					{ sprintf(
						/* translators: %d: number of candidate date ranges collected. */
						__(
							'Give the ideal dates and up to %d alternatives, best first.  Leave an end date blank for a single day.',
							'marthrown-enquiry-hub'
						),
						RANGE_SLOTS.length - 1
					) }
				</p>

				<FieldError code={ errors.date_ranges } />
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

			<SelectControl
				label={ LABELS.event_type }
				value={ values.event_type }
				options={ taxonomyOptions( eventTypeOptions ) }
				onChange={ set( 'event_type' ) }
				help={ optionalHint() }
				className="meh-form__field"
				__nextHasNoMarginBottom
			/>
			<FieldError code={ errors.event_type } />

			<SelectControl
				label={ LABELS.site_exclusivity }
				value={ values.site_exclusivity }
				options={ taxonomyOptions( siteExclusivityOptions ) }
				onChange={ set( 'site_exclusivity' ) }
				help={ optionalHint() }
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
 * Create starts empty with three blank range slots; edit starts from the
 * displayed enquiry's stored values, which is also the baseline the
 * altered-field comparison measures against.
 *
 * @param {Object|null} enquiry Enquiry being corrected, or null when creating.
 * @return {Object} Form state.
 */
function initialValues( enquiry ) {
	const stored = enquiry || {};

	return {
		first_name: text( stored.first_name ),
		last_name: text( stored.last_name ),
		email: text( stored.email ),
		phone: text( stored.phone ),
		total_guests: text( stored.total_guests ),
		ranges: storedRanges( stored.date_ranges ),
		// The dropdown holds one value: the first of whatever is stored, empty
		// when nothing is. A stored set of more than one narrows to its first
		// member the moment this form is opened on it (see the TAXONOMIES
		// comment above) rather than only on save.
		event_type: list( stored.event_type )[ 0 ] || '',
		site_exclusivity: list( stored.site_exclusivity )[ 0 ] || '',
		message: text( stored.message ),
	};
}

/**
 * The stored candidate ranges as the form's three slots.
 *
 * Rank order is preserved exactly as stored — the first stored range is the
 * ideal one — and the slots are padded to three so every input has a value to
 * render. An enquiry stored before the cap existed can hold more than three
 * ranges; those beyond the third are not shown, and saving the form therefore
 * drops them. That narrowing is the same one already accepted for
 * `event_type`/`site_exclusivity`: it happens on open, not silently on save.
 *
 * @param {*} stored Stored `date_ranges`.
 * @return {Array<{start: string, end: string}>} Exactly three slots.
 */
function storedRanges( stored ) {
	const ranges = Array.isArray( stored ) ? stored : [];

	return RANGE_SLOTS.map( ( slot, index ) => {
		const range = ranges[ index ];

		return {
			start: range ? text( range.start ) : '',
			end: range ? text( range.end ) : '',
		};
	} );
}

/**
 * The range slots as the API's `date_ranges` list.
 *
 * A slot with neither bound filled is not a range the enquirer named, so it is
 * dropped rather than sent empty — which is what lets the second and third
 * slots be optional. A slot with only a start is a single day, both bounds the
 * same. A slot with only an end is sent as it stands, half-drawn, so the server
 * answers `incomplete_range` rather than this form quietly inventing a start.
 *
 * @param {Array<{start: string, end: string}>} slots Form range slots.
 * @return {Array<{start: string, end: string}>} Submittable ranges.
 */
function submittedRanges( slots ) {
	const ranges = [];

	( Array.isArray( slots ) ? slots : [] ).forEach( ( slot ) => {
		const start = text( slot && slot.start ).trim();
		const end = text( slot && slot.end ).trim();

		if ( '' === start && '' === end ) {
			return;
		}

		ranges.push( { start, end: '' === end ? start : end } );
	} );

	return ranges;
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
		date_ranges: submitted( values, 'date_ranges' ),
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
 * @return {string|string[]|Array<Object>} Submittable value.
 */
function submitted( values, field ) {
	if ( 'date_ranges' === field ) {
		return submittedRanges( values.ranges );
	}

	if ( TAXONOMIES.includes( field ) ) {
		const chosen = values[ field ].trim();

		return '' === chosen ? [] : [ chosen ];
	}

	return values[ field ].trim();
}

/**
 * Whether two submittable values are the same value.
 *
 * Lists are compared in order, not as sets: promoting the second candidate
 * range to the ideal one names the same days and changes which of them the
 * enquirer would rather have, so it is an alteration worth submitting.
 *
 * @param {string|string[]|Array<Object>} a First value.
 * @param {string|string[]|Array<Object>} b Second value.
 * @return {boolean} True when equal.
 */
function same( a, b ) {
	if ( Array.isArray( a ) || Array.isArray( b ) ) {
		return (
			JSON.stringify( Array.isArray( a ) ? a : [] ) ===
			JSON.stringify( Array.isArray( b ) ? b : [] )
		);
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
 * A taxonomy vocabulary as `SelectControl` options, with a leading blank
 * option so the field can be left unset — both taxonomies are optional under
 * the Manual Validation Profile.
 *
 * @param {string[]} values Configured vocabulary.
 * @return {Array<{label: string, value: string}>}
 */
function taxonomyOptions( values ) {
	return [
		{ label: __( '— None —', 'marthrown-enquiry-hub' ), value: '' },
		...values.map( ( value ) => ( { label: value, value } ) ),
	];
}
