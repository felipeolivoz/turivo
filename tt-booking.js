jQuery(function ($) {
    'use strict';

    const TTBooking = {
        // =========================================================
        // CONFIG GLOBAL
        // =========================================================
        config: {
            countryCode: 'cl',
            routeDebounceMs: 800,
            minHoursAhead: 6,
            maxDistanceKm: 5000,
            map: {
                defaultCenter: { lat: -33.4489, lng: -70.6693 },
                defaultZoom: 13,
                polyline: {
                    strokeColor: '#fc711d',
                    strokeOpacity: 0.9,
                    strokeWeight: 5
                }
            }
        },

        // =========================================================
        // ESTADO GLOBAL
        // =========================================================
state: {
    initialized: false,
    step: 1,

    step1: {
        origin: '',
        destination: '',
        date: '',
        time: '',
        passengers: 1,
        transferType: '',
        transferTypeText: '',
        allowsStops: false
    },

    step2: {
        customerRut: '',
        customerName: '',
        customerEmail: '',
        customerPhone: '',
        vehicleTypeId: '',
        vehicleTypeText: ''
    },

    route: {
        distanceKm: 0,
        durationText: '',
        originFormatted: '',
        destinationFormatted: '',
        stops: [],
        polyline: '',
        points: []
    },

    pricing: {
        totalPrice: 0,
        vehicleName: '',
        priceDetails: {},     // aquí iremos guardando breakdown del backend
        vehicleFactorAmount: 0,
        stopsPrice: 0,
        stopsCount: 0,
        surcharges: {
            surcharge_amount: 0,
            surcharge_details: []
        }
    },

    _sixHourToastShown: false
},



        maps: {
            autocompleteOrigin: null,
            autocompleteDestination: null,
            directionsService: null,
            lastRouteRequestTs: 0,
            isCalculatingRoute: false
        },

        // Cache para optimizar el modal de resumen
        cachedSummary: null,
        _mapInitialized: false,
        cachedSurchargeHtml: null,
        cachedSurchargeHtmlKey: null,
        _summaryMap: null,

        // =========================================================
        // HELPERS GENERALES
        // =========================================================
        debounce(fn, wait) {
            let t;
            return (...args) => {
                clearTimeout(t);
                t = setTimeout(() => fn.apply(this, args), wait);
            };
        },

        formatCurrency(amount) {
            if (amount === null || amount === undefined || isNaN(amount)) return '$0';
            return '$' + Math.round(Number(amount)).toLocaleString('es-CL');
        },

        formatDate(dateStr) {
            if (!dateStr) return 'No especificado';
            try {
                const d = new Date(dateStr + 'T00:00:00');
                return d.toLocaleDateString('es-CL', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit'
                });
            } catch (e) {
                return dateStr;
            }
        },

        formatPhone(phone) {
            if (!phone) return 'No especificado';
            const cleaned = phone.replace(/\D/g, '');
            if (cleaned.length === 9 && cleaned.startsWith('9')) {
                return '+56 9 ' + cleaned.slice(1, 5) + ' ' + cleaned.slice(5);
            }
            return phone;
        },

        // Mantener booking_data global coherente con backend (Transbank / DB)
    syncWindowBooking() {
    const s1 = { ...this.state.step1 };
    const s2 = { ...this.state.step2 };
    const r  = this.state.route;
    const p  = this.state.pricing;

    // ================================
    // Normalización de precios
    // ================================
    const pd = p.priceDetails || {};

    const basePrice       = pd.base_price      ?? pd.basePrice      ?? 0;
    const distancePrice   = pd.distance_price  ?? pd.distancePrice  ?? 0;
    const passengersPrice = pd.passengers_price?? pd.passengersPrice?? 0;

    const vehicleFactorAmount = p.vehicleFactorAmount || 0;
    const stopsPrice          = p.stopsPrice          || 0;
    const surchargesTotal     = p.surcharges ? (p.surcharges.surcharge_amount || 0) : 0;

    const subtotalBase = basePrice + distancePrice + passengersPrice;
    const subtotalFinal = subtotalBase + vehicleFactorAmount + stopsPrice + surchargesTotal;

    const total = p.totalPrice || subtotalFinal;

    // ================================
    // FORMATO A (oficial del backend)
    // ================================
    const booking_data = {
        step1: s1,
        step2: s2,

        distance: r.distanceKm || 0,
        duration: r.durationText || "",

        stops: Array.isArray(r.stops) ? [...r.stops] : [],

        route_polyline: r.polyline || "",
        route_points: Array.isArray(r.points) ? r.points : [],

        priceDetails: {
            base_price: basePrice,
            distance_price: distancePrice,
            passengers_price: passengersPrice,
            vehicle_factor: vehicleFactorAmount,
            stops_price: stopsPrice,
            surcharges_total: surchargesTotal,
            subtotal: subtotalBase
        },

        surchargeData: {
            surcharge_amount: surchargesTotal,
            surcharge_details: (p.surcharges && p.surcharges.surcharge_details) || []
        },

        totalPrice: total
    };

    window.tt_booking_data = booking_data;
},


        // =========================================================
        // HELPERS DE VALIDACIÓN (INLINE)
        // =========================================================
        setFieldError($el, msg) {
            if (!$el || !$el.length) return;
            const $group = $el.closest('.tt-form-group');
            if (!$group.length) return;
            const $msg = $group.find('.tt-error-msg');
            if (!$msg.length) return;

            if (msg) {
                $el.addClass('tt-invalid').removeClass('tt-valid');
                $msg.text(msg);
            } else {
                $el.removeClass('tt-invalid').addClass('tt-valid');
                $msg.text('');
            }
        },

        clearFieldError($el) {
            if (!$el || !$el.length) return;
            const $group = $el.closest('.tt-form-group');
            if (!$group.length) return;
            const $msg = $group.find('.tt-error-msg');
            $el.removeClass('tt-invalid tt-valid');
            if ($msg.length) $msg.text('');
        },

        setGlobalError(msg) {
            $('#tt-step3-error, #tt-summary-error').text(msg || '');
        },

        // =========================================================
        // INIT GLOBAL
        // =========================================================
        init() {
            if (this.state.initialized) return;
            this.state.initialized = true;

            this.initStep1();
            this.initStep2();
            this.initStep3();

            this.syncWindowBooking();
        },

        // =========================================================
        // STEP 1
        // =========================================================
        initStep1() {
            if (!$('#step-1').length) return;

            const $date = $('#tt-date');
            const $time = $('#tt-time');
            const $passengers = $('#tt-passengers');
            const $transfer = $('#tt-transfer-type');

            const today = new Date().toISOString().split('T')[0];
            $date.attr('min', today);

            const now = new Date();
            const nextHour = new Date(now.getTime() + 60 * 60 * 1000);
            const defaultTime = String(nextHour.getHours()).padStart(2, '0') + ':00';
            $time.val(defaultTime);

            if (!$passengers.val()) $passengers.val('1');

            this.populateTransferTypes();

            const debouncedRoute = this.debounce(() => {
                this.readStep1Form();
                this.validateStep1();
                this.calculateRoute();
            }, this.config.routeDebounceMs);

            $('#tt-origin, #tt-destination').on('input', debouncedRoute);

            $date.on('change', () => {
                this.readStep1Form();
                this.validateStep1();
            });

            $time.on('change', () => {
                this.readStep1Form();
                this.validateStep1();
            });

            $passengers.on('change input', () => {
                this.readStep1Form();
                this.validateStep1();
            });

            $transfer.on('change', () => {
                this.readStep1Form();
                this.validateStep1();
            });

            $('.tt-next-btn[data-next="2"]').on('click', (e) => {
                e.preventDefault();
                this.goToStep2();
            });

            this.initMapsAutocomplete();
            this.validateStep1();
        },

        populateTransferTypes() {
            const $transfer = $('#tt-transfer-type');
            if (!$transfer.length) return;

            $transfer.empty().append(
                '<option value="">Seleccionar tipo de traslado</option>'
            );

            if (typeof tt_pricing_data === 'undefined' || !tt_pricing_data.transfer_types) {
                return;
            }

            tt_pricing_data.transfer_types.forEach((type) => {
                const id = type.id || type.ID;
                const name = type.name || type.nombre || 'Traslado';
                const desc = type.description || type.descripcion || '';
                const allowsStops =
                    type.allows_stops == 1 || type.allows_stops === true ? '1' : '0';

                if (!id) return;

                $transfer.append(`
                    <option value="${id}"
                            data-allows-stops="${allowsStops}"
                            title="${desc}">
                        ${name}
                    </option>
                `);
            });
        },

        readStep1Form() {
            const $transferSel = $('#tt-transfer-type option:selected');

            this.state.step1 = {
                origin: $('#tt-origin').val().trim(),
                destination: $('#tt-destination').val().trim(),
                date: $('#tt-date').val(),
                time: $('#tt-time').val(),
                passengers: parseInt($('#tt-passengers').val() || '1', 10),
                transferType: $('#tt-transfer-type').val(),
                transferTypeText: $transferSel.text() || '',
                allowsStops: $transferSel.data('allows-stops') == 1
            };

            if (this.state.step1.allowsStops) {
                $('#tt-intermediate-stops').show();
            } else {
                $('#tt-intermediate-stops').hide();
                this.state.route.stops = [];
                $('#tt-stops-container .tt-stop-item input').val('');
                $('#tt-stops-error').text('');
            }

            this.syncWindowBooking();
        },

        validateStep1() {
            let isValid = true;
            let firstError = '';

            const setError = ($el, msg) => {
                if (msg && !firstError) firstError = msg;
                if (msg) isValid = false;
                this.setFieldError($el, msg || '');
            };

            const s = this.state.step1;

            if (!s.origin) {
                setError($('#tt-origin'), 'Origen requerido');
            } else {
                setError($('#tt-origin'), '');
            }

            if (!s.destination) {
                setError($('#tt-destination'), 'Destino requerido');
            } else {
                setError($('#tt-destination'), '');
            }

            if (!s.date) {
                setError($('#tt-date'), 'Fecha requerida');
            } else {
                setError($('#tt-date'), '');
            }

            if (!s.time) {
                setError($('#tt-time'), 'Hora requerida');
            } else {
                setError($('#tt-time'), '');
            }

            if (!s.passengers || s.passengers < 1 || s.passengers > 20) {
                setError($('#tt-passengers'), 'Pasajeros debe ser entre 1 y 20');
            } else {
                setError($('#tt-passengers'), '');
            }

            if (!s.transferType) {
                setError($('#tt-transfer-type'), 'Tipo de traslado requerido');
            } else {
                setError($('#tt-transfer-type'), '');
            }

            const rawDate = $('#tt-date').val();
            const rawTime = $('#tt-time').val();

            if (rawDate && rawTime) {
                const [year, month, day] = rawDate.split('-').map(Number);
                const [hh, mm] = rawTime.split(':').map(Number);

                const selected = new Date(year, month - 1, day, hh, mm, 0);
                const now = new Date();

                const diffHours =
                    (selected.getTime() - now.getTime()) / 1000 / 60 / 60;

                if (diffHours < this.config.minHoursAhead) {
                    const msg = 'Tiempo mínimo para reserva 6 hrs.';
                    setError($('#tt-date'), msg);
                    setError($('#tt-time'), msg);
                }
            }

            if (isValid && this.state.route.distanceKm <= 0) {
                setError(
                    $('#tt-destination'),
                    'Debes calcular la ruta antes de continuar'
                );
            }

            const $next = $('.tt-next-btn[data-next="2"]');
            if (isValid) {
                $next.prop('disabled', false).removeClass('tt-disabled');
            } else {
                $next.prop('disabled', true).addClass('tt-disabled');
            }

            return { isValid, firstError };
        },

        // =========================================================
        // GOOGLE MAPS
        // =========================================================
        // Este es el callback interno que llama __ttgmaps_init
        _gmapsInit() {
            this.initMapsAutocomplete();
        },

        initMapsAutocomplete() {
            if (
                typeof google === 'undefined' ||
                !google.maps ||
                !google.maps.places ||
                !google.maps.places.Autocomplete
            ) {
                setTimeout(() => this.initMapsAutocomplete(), 1200);
                return;
            }

            try {
                const options = {
                    componentRestrictions: { country: this.config.countryCode },
                    fields: ['formatted_address', 'geometry', 'name']
                };

                const originInput = document.getElementById('tt-origin');
                const destInput = document.getElementById('tt-destination');

                if (originInput) {
                    this.maps.autocompleteOrigin =
                        new google.maps.places.Autocomplete(originInput, options);

                    this.maps.autocompleteOrigin.addListener('place_changed', () => {
                        const place = this.maps.autocompleteOrigin.getPlace();
                        const $origin = $(originInput);

                        if (!place.geometry) {
                            originInput.value = '';
                            this.setFieldError(
                                $origin,
                                'Selecciona una dirección válida (origen).'
                            );
                            return;
                        }

                        originInput.value =
                            place.formatted_address || place.name || '';
                        this.setFieldError($origin, '');
                        this.readStep1Form();
                        this.calculateRoute();
                    });
                }

                if (destInput) {
                    this.maps.autocompleteDestination =
                        new google.maps.places.Autocomplete(destInput, options);

                    this.maps.autocompleteDestination.addListener(
                        'place_changed',
                        () => {
                            const place = this.maps.autocompleteDestination.getPlace();
                            const $dest = $(destInput);

                            if (!place.geometry) {
                                destInput.value = '';
                                this.setFieldError(
                                    $dest,
                                    'Selecciona una dirección válida (destino).'
                                );
                                return;
                            }

                            destInput.value =
                                place.formatted_address || place.name || '';
                            this.setFieldError($dest, '');
                            this.readStep1Form();
                            this.calculateRoute();
                        }
                    );
                }

                this.maps.directionsService = new google.maps.DirectionsService();
                this.initStopsManagement();
            } catch (e) {
                // Silencioso en producción
            }
        },

        // Gestión de paradas intermedias
initStopsManagement() {
    const self = this;

    // Añadir parada
    $(document).on('click', '#tt-add-stop', function (e) {
        e.preventDefault();
        const $container = $('#tt-stops-container');
        const count = $container.children('.tt-stop-item').length;

        if (count >= 5) {
            $('#tt-stops-error').text('Máximo 5 paradas permitidas');
            return;
        }

        $('#tt-stops-error').text('');

        const $item = $(`
            <div class="tt-stop-item">
                <input type="text"
                       class="tt-form-control tt-stop-input tt-address-input"
                       placeholder="Dirección de parada intermedia">
                <button type="button"
                        class="tt-remove-stop"
                        aria-label="Eliminar parada">&times;</button>
            </div>
        `);

        $container.append($item);
        self.initStopAutocomplete($item.find('.tt-stop-input')[0]);
    });

    // Eliminar parada
    $(document).on('click', '.tt-remove-stop', function (e) {
        e.preventDefault();
        $(this).closest('.tt-stop-item').remove();
        self.updateStopsFromDOM();
        self.calculateRoute();

        if ($('#tt-stops-container .tt-stop-item').length < 5) {
            $('#tt-stops-error').text('');
        }
    });

    // Inicializar las que ya existan en DOM
    $('.tt-stop-input').each((i, el) => self.initStopAutocomplete(el));
},


        initStopAutocomplete(inputEl) {
            if (!inputEl || inputEl.dataset.autocompleteInit) return;

            if (
                typeof google === 'undefined' ||
                !google.maps ||
                !google.maps.places ||
                !google.maps.places.Autocomplete
            ) {
                return;
            }

            const autocomplete = new google.maps.places.Autocomplete(inputEl, {
                types: ['geocode', 'establishment'],
                componentRestrictions: { country: this.config.countryCode },
                fields: ['formatted_address', 'geometry']
            });

            autocomplete.addListener('place_changed', () => {
                const place = autocomplete.getPlace();

                if (!place.geometry || !place.formatted_address) {
                    inputEl.classList.add('tt-invalid');
                } else {
                    inputEl.value = place.formatted_address;
                    inputEl.classList.add('tt-valid');
                    inputEl.classList.remove('tt-invalid');
                    this.updateStopsFromDOM();
                    this.calculateRoute();
                }
            });

            inputEl.dataset.autocompleteInit = '1';
        },

   updateStopsFromDOM() {
    const stops = [];
    $('#tt-stops-container .tt-stop-input').each(function (idx) {
        const addr = $(this).val().trim();
        if (addr) {
            stops.push({
                address: addr,
                order: idx + 1
            });
        }
    });

    this.state.route.stops = stops;
    this.state.pricing.stopsCount = stops.length;
    this.syncWindowBooking();
},


calculateRoute() {
    if (!this.maps.directionsService) return;

    const now = Date.now();
    if (now - this.maps.lastRouteRequestTs < this.config.routeDebounceMs) {
        return;
    }
    this.maps.lastRouteRequestTs = now;

    const s1 = this.state.step1;
    if (!s1.origin || !s1.destination) return;

    const origin = s1.origin;
    const destination = s1.destination;

    const waypoints = this.state.route.stops.map((stop) => ({
        location: stop.address,
        stopover: true
    }));

    this.maps.isCalculatingRoute = true;

    const request = {
        origin,
        destination,
        waypoints,
        travelMode: 'DRIVING',
        unitSystem: google.maps.UnitSystem.METRIC,
        optimizeWaypoints: false,
        region: this.config.countryCode
    };

    const $nextBtn = $('.tt-next-btn[data-next="2"]');
    const originalText = $nextBtn.html();

    $nextBtn
        .prop('disabled', true)
        .html('<span class="tt-loading">Calculando ruta...</span>');

    this.maps.directionsService.route(request, (result, status) => {
        this.maps.isCalculatingRoute = false;
        $nextBtn.prop('disabled', false).html(originalText);

        if (status !== 'OK' || !result || !result.routes || !result.routes.length) {
            this.state.route.distanceKm = 0;
            this.state.route.durationText = '';
            this.state.route.originFormatted = '';
            this.state.route.destinationFormatted = '';
            this.state.route.polyline = '';
            this.state.route.points = [];

            this.syncWindowBooking();
            this.setFieldError(
                $('#tt-destination'),
                'No se pudo calcular la ruta. Revisa las direcciones.'
            );
            this.validateStep1();
            return;
        }

        const route = result.routes[0];
        let totalMeters = 0;
        let totalSeconds = 0;
        const points = [];

        route.legs.forEach((leg) => {
            if (!leg.distance || !leg.duration) return;

            totalMeters += Number(leg.distance.value || 0);
            totalSeconds += Number(leg.duration.value || 0);

            leg.steps.forEach((step) => {
                if (!step.end_location) return;
                points.push({
                    lat: step.end_location.lat(),
                    lng: step.end_location.lng()
                });
            });
        });

        const distanceKm = totalMeters / 1000;

        if (!distanceKm || distanceKm <= 0) {
            this.state.route.distanceKm = 0;
            this.state.route.durationText = '';
            this.syncWindowBooking();
            this.setFieldError(
                $('#tt-destination'),
                'No se pudo calcular la ruta. Revisa las direcciones.'
            );
            this.validateStep1();
            return;
        }

        if (distanceKm > this.config.maxDistanceKm) {
            this.state.route.distanceKm = 0;
            this.state.route.durationText = '';
            this.syncWindowBooking();
            this.setFieldError(
                $('#tt-destination'),
                'Distancia excesiva. Revisa las direcciones.'
            );
            this.validateStep1();
            return;
        }

        const minutes = Math.round(totalSeconds / 60);

        this.state.route.distanceKm = distanceKm;
        this.state.route.durationText = minutes > 0 ? minutes + ' min' : '';
        this.state.route.originFormatted = route.legs[0].start_address || origin;
        this.state.route.destinationFormatted =
            route.legs[route.legs.length - 1].end_address || destination;

        if (route.overview_polyline && route.overview_polyline.points) {
            this.state.route.polyline = route.overview_polyline.points;
        } else {
            this.state.route.polyline = '';
        }

        this.state.route.points = points;

        this.syncWindowBooking();
        this.setFieldError($('#tt-destination'), '');
        this.validateStep1();
    });
},


        // =========================================================
        // STEP 2
        // =========================================================
        initStep2() {
            if (!$('#step-2').length) return;

            const self = this;

            $('#tt-customer-name, #tt-customer-email, #tt-customer-phone').on(
                'input',
                () => {
                    self.readStep2Form();
                    self.validateStep2();
                }
            );

            $('#tt-vehicle-type').on('change', function () {
                self.readStep2Form();
                self.validateStep2();
                if (self.state.route.distanceKm > 0) {
                    self.calculatePrice();
                }
            });

            $('#tt-customer-rut').on('input', function () {
                const raw = $(this)
                    .val()
                    .replace(/\./g, '')
                    .replace(/-/g, '')
                    .toUpperCase();

                if (raw.length > 1) {
                    let formatted = '';
                    for (let i = 0; i < raw.length - 1; i++) {
                        if (i > 0 && (raw.length - 1 - i) % 3 === 0) {
                            formatted += '.';
                        }
                        formatted += raw.charAt(i);
                    }
                    formatted += '-' + raw.charAt(raw.length - 1);
                    $(this).val(formatted);
                }

                self.readStep2Form();
                self.validateStep2();
            });

            $('.tt-prev-btn[data-prev="1"]').on('click', (e) => {
                e.preventDefault();
                this.goBackToStep1();
            });

            $('.tt-next-btn[data-next="3"]').on('click', (e) => {
                e.preventDefault();
                this.goToStep3();
            });

            this.loadVehicleTypes();
            this.validateStep2();
        },

        readStep2Form() {
            this.state.step2 = {
                customerRut: $('#tt-customer-rut').val().trim(),
                customerName: $('#tt-customer-name').val().trim(),
                customerEmail: $('#tt-customer-email').val().trim(),
                customerPhone: $('#tt-customer-phone').val().trim(),
                vehicleTypeId: $('#tt-vehicle-type').val(),
                vehicleTypeText: $('#tt-vehicle-type option:selected').text()
            };

            this.syncWindowBooking();
        },

        validateEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        },

        validateStep2() {
            const s2 = this.state.step2;
            let isValid = true;
            let firstError = '';

            const setError = ($el, msg) => {
                if (msg && !firstError) firstError = msg;
                if (msg) isValid = false;
                this.setFieldError($el, msg || '');
            };

            if (!s2.customerName || s2.customerName.length < 2) {
                setError($('#tt-customer-name'), 'Nombre requerido');
            } else {
                setError($('#tt-customer-name'), '');
            }

            if (!s2.customerEmail) {
                setError($('#tt-customer-email'), 'Email requerido');
            } else if (!this.validateEmail(s2.customerEmail)) {
                setError($('#tt-customer-email'), 'Email inválido');
            } else {
                setError($('#tt-customer-email'), '');
            }

            const phoneDigits = s2.customerPhone.replace(/\D/g, '');
            if (!phoneDigits) {
                setError($('#tt-customer-phone'), 'Teléfono requerido');
            } else if (phoneDigits.length < 8) {
                setError(
                    $('#tt-customer-phone'),
                    'Teléfono inválido (mínimo 8 dígitos)'
                );
            } else {
                setError($('#tt-customer-phone'), '');
            }

            if (!s2.vehicleTypeId) {
                setError($('#tt-vehicle-type'), 'Seleccione un tipo de vehículo');
            } else {
                setError($('#tt-vehicle-type'), '');
            }

            const rut = s2.customerRut;
            const $rutEl = $('#tt-customer-rut');

            if (rut) {
                if (!this.validateRUT(rut)) {
                    setError($rutEl, 'RUT inválido');
                } else {
                    setError($rutEl, '');
                }
            } else {
                this.clearFieldError($rutEl);
            }

            const $next = $('.tt-next-btn[data-next="3"]');
            if (isValid) {
                $next.prop('disabled', false).removeClass('tt-disabled');
            } else {
                $next.prop('disabled', true).addClass('tt-disabled');
            }

            return { isValid, firstError };
        },

        validateRUT(rut) {
            if (!rut || typeof rut !== 'string') return false;

            rut = rut.replace(/\./g, '').replace(/-/g, '').toUpperCase();
            if (!/^[0-9]+[0-9K]$/.test(rut)) return false;

            const numero = rut.slice(0, -1);
            const dv = rut.slice(-1);

            let suma = 0;
            let multiplo = 2;

            for (let i = numero.length - 1; i >= 0; i--) {
                suma += parseInt(numero.charAt(i), 10) * multiplo;
                multiplo = multiplo < 7 ? multiplo + 1 : 2;
            }

            const dvEsperado = 11 - (suma % 11);
            const dvCalculado =
                dvEsperado === 11
                    ? '0'
                    : dvEsperado === 10
                    ? 'K'
                    : dvEsperado.toString();

            return dvCalculado === dv;
        },

        loadVehicleTypes() {
            const $select = $('#tt-vehicle-type');
            if (!$select.length) return;

            $select
                .empty()
                .append('<option value="">Seleccionar tipo de vehículo</option>');

            if (
                typeof tt_pricing_data !== 'undefined' &&
                tt_pricing_data.vehicle_types &&
                tt_pricing_data.vehicle_types.length
            ) {
                tt_pricing_data.vehicle_types.forEach((v, idx) => {
                    const id = v.id;
                    if (!id) return;

                    const name = v.name || `Vehículo ${idx + 1}`;
                    const cap = v.capacity || 4;
                    const priceKm = v.price_per_km || 0;
                    const base = v.base_price || 0;

                    $select.append(`
                        <option value="${id}"
                                data-price-km="${priceKm}"
                                data-base-price="${base}"
                                data-capacity="${cap}">
                            ${name} (${cap} pasajeros)
                        </option>
                    `);
                });

                return;
            }

            if (typeof tt_ajax === 'undefined') return;

            $.ajax({
                url: tt_ajax.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'tt_get_vehicle_types',
                    nonce: tt_ajax.nonce
                }
            }).done((resp) => {
                if (!resp.success || !resp.data || !resp.data.length) return;

                resp.data.forEach((v, idx) => {
                    const id = v.id;
                    if (!id) return;

                    const name = v.name || `Vehículo ${idx + 1}`;
                    const cap = v.capacity || 4;
                    const priceKm = v.price_per_km || 0;
                    const base = v.base_price || 0;

                    $select.append(`
                        <option value="${id}"
                                data-price-km="${priceKm}"
                                data-base-price="${base}"
                                data-capacity="${cap}">
                            ${name} (${cap} pasajeros)
                        </option>
                    `);
                });
            });
        },

calculatePrice() {
    if (typeof tt_ajax === 'undefined') return;

    const s1 = this.state.step1;
    const s2 = this.state.step2;

    if (!s2.vehicleTypeId || this.state.route.distanceKm <= 0) return;

    const $nextBtn = $('.tt-next-btn[data-next="3"]');
    const originalText = $nextBtn.html();

    $nextBtn
        .prop('disabled', true)
        .html('<span class="tt-loading">Calculando precio...</span>');

    $.ajax({
        url: tt_ajax.ajax_url,
        method: 'POST',
        dataType: 'json',
        data: {
            action: 'calculate_price',
            nonce: tt_ajax.nonce,
            distance: this.state.route.distanceKm,
            vehicle_type_id: s2.vehicleTypeId,
            passengers: s1.passengers,
            transfer_date: s1.date,
            transfer_time: s1.time
        }
    })
        .done((resp) => {
            if (!resp.success || !resp.data) {
                this.setFieldError(
                    $('#tt-vehicle-type'),
                    'No se pudo calcular el precio, intenta nuevamente.'
                );
                return;
            }

            const d = resp.data;
            const br = d.breakdown || {};

            const base = Number(br.base || 0);
            const distancePrice = Number(br.distance || 0);
            const passengersPrice = Number(br.passengers || 0);
            const vehicleFactorAmount = Number(br.vehicleFactorAmount || 0);
            const final = Number(br.final || 0);

            // Guardamos en state.pricing con snake_case compatible
            this.state.pricing.totalPrice = final;
            this.state.pricing.vehicleName =
                this.state.step2.vehicleTypeText || '';

            this.state.pricing.priceDetails = {
                base_price: base,
                distance_price: distancePrice,
                passengers_price: passengersPrice,
                vehicle_factor: vehicleFactorAmount,
                subtotal:
                    base +
                    distancePrice +
                    passengersPrice +
                    vehicleFactorAmount
            };

            this.state.pricing.vehicleFactorAmount = vehicleFactorAmount;
            this.state.pricing.stopsPrice = 0; // por ahora sin recargo por paradas
            this.state.pricing.stopsCount =
                this.state.route.stops.length || 0;

            // Sin recargos adicionales aún
            this.state.pricing.surcharges = {
                surcharge_amount: 0,
                surcharge_details: []
            };

            this.cachedSummary = null;
            this.cachedSurchargeHtml = null;
            this.cachedSurchargeHtmlKey = null;

            this.syncWindowBooking();
            this.setFieldError($('#tt-vehicle-type'), '');
        })
        .always(() => {
            $nextBtn.prop('disabled', false).html(originalText);
        });
},



        // =========================================================
        // STEP 3 – Resumen + Pago TBK
        // =========================================================
        initStep3() {
            if (!$('#step-3').length) return;

            this.ensureSummaryModal();

            $(document).on('click', '.tt-show-summary-btn', (e) => {
                e.preventDefault();
                this.openSummaryModal();
            });
        },

       ensureSummaryModal() {
    if ($('#tt-summary-modal').length) return;

    const html = `
        <div id="tt-summary-modal" class="tt-summary-modal" style="display:none;">
            <div class="tt-summary-overlay"></div>

            <div class="tt-summary-container">

                <!-- ========================= -->
                <!-- NUEVO HEADER SIN ICONO -->
                <!-- ========================= -->
                <div class="tt-summary-header">
                    <div>
                        <h2 class="tt-summary-title">Confirmación del Viaje</h2>
                        <p class="tt-summary-subtitle">Revisa todos los detalles antes de continuar al pago.</p>
                    </div>
                    <button type="button" class="tt-summary-close" aria-label="Cerrar">&times;</button>
                </div>

                <!-- ========================= -->
                <!-- BODY DEL MODAL -->
                <!-- ========================= -->
                <div class="tt-summary-body" id="tt-modal-content">
                    <div class="tt-loading-summary">
                        <div class="tt-loading-spinner"></div>
                        <p>Cargando tu resumen...</p>
                    </div>
                </div>

                <!-- ========================= -->
                <!-- FOOTER -->
                <!-- ========================= -->
                <div class="tt-summary-footer">
                    <button type="button" class="tt-summary-btn tt-summary-back">← Editar</button>
                    <button type="button" class="tt-summary-btn tt-summary-pay tt-pay-btn" disabled>Pagar con Transbank</button>
                    <span class="tt-summary-error" id="tt-summary-error"></span>
                </div>
            </div>
        </div>
    `;
            $('body').append(html);

         // Cerrar modal normal (overlay y X)
$(document).on("click", ".tt-summary-overlay, .tt-summary-close", (e) => {
    e.preventDefault();
    this.closeSummaryModal();
});

// Botón EDITAR (volver a STEP 2)
$(document).on("click", ".tt-summary-back", (e) => {
    e.preventDefault();

    this.closeSummaryModal();

    // Volver a Step 2
    $("#step-3").fadeOut(200, () => {
        $("#step-2").fadeIn(200).addClass("active");
        $("#step-3").removeClass("active");

        $(".tt-step").removeClass("active completed");
        $('.tt-step[data-step="1"]').addClass("completed");
        $('.tt-step[data-step="2"]').addClass("active");

        this.state.step = 2;

        $("html, body").animate(
            { scrollTop: $(".tt-booking-form-modern").offset().top - 20 },
            300
        );
    });
});


            // Botón Transbank
            $(document).on("click", ".tt-summary-pay", (e) => {
                e.preventDefault();
                this.processPayment();
            });
        },

openSummaryModal() {
    this.syncWindowBooking();
    this.ensureSummaryModal();
    this.setGlobalError('');

    const dataKey = JSON.stringify(window.tt_booking_data || {});
    this.cachedSummary = dataKey;

    this.updateSummaryContent();
    this.destroySummaryMap();

    const $modal = $('#tt-summary-modal');
    $('body').addClass('tt-modal-open');

    $modal.fadeIn(200, () => {
        setTimeout(() => {
            this._initSummaryMapWhenReady();
        }, 200);
    });
},
// ← IMPORTANTE: ESTA COMA **SÍ o SÍ** DEBE ESTAR AQUÍ

/**
 * Espera a que Google Maps esté listo antes de iniciar el mapa
 */
_initSummaryMapWhenReady(attempt = 0) {
    if (attempt > 20) {
        console.warn("Google Maps no cargó para el modal.");
        this.renderFallbackMap(document.getElementById('tt-route-map-modal'));
        return;
    }

    if (typeof google !== "undefined" &&
        google.maps &&
        typeof google.maps.Map === "function") {

        this.initSummaryMap();

        setTimeout(() => {
            if (this._summaryMap) {
                google.maps.event.trigger(this._summaryMap, "resize");
                const center = this._summaryMap.getCenter();
                if (center) this._summaryMap.setCenter(center);
            }
        }, 250);

        return;
    }

    setTimeout(() => this._initSummaryMapWhenReady(attempt + 1), 100);
},




        closeSummaryModal() {
            $('#tt-summary-modal').fadeOut(200, () => {
                $('body').removeClass('tt-modal-open');
                this.destroySummaryMap();
            });
        },

updateSummaryContent() {
    this.syncWindowBooking();

    const s1 = this.state.step1;
    const s2 = this.state.step2;
    const r = this.state.route;
    const p = this.state.pricing;

    const total =
        Number(p.totalPrice || p.total_price || p.total || 0);

    if (!total || total <= 0) {
        this.setGlobalError('No se pudo calcular el precio');
        $('#tt-modal-content').html('<p>No se pudo calcular el precio.</p>');
        $('.tt-pay-btn').prop('disabled', true).addClass('tt-disabled');
        return;
    }

    const distanceKm = Number(r.distanceKm || 0);
    const distanceLabel =
        distanceKm > 0 ? distanceKm.toFixed(1) + ' km' : '0 km';

    const additionalPassengers =
        s1.passengers > 1 ? s1.passengers - 1 : 0;

    const pd = p.priceDetails || {};
    const basePrice = Number(pd.basePrice || pd.base_price || 0);
    const distancePrice = Number(
        pd.distancePrice || pd.distance_price || 0
    );
    const passengersPrice = Number(
        pd.passengersPrice || pd.passengers_price || 0
    );

    const vehicleFactorAmount =
        Number(this.state.pricing.vehicleFactorAmount || 0);

    const subtotalBase =
        Number(pd.subtotal) ||
        basePrice + distancePrice + passengersPrice + vehicleFactorAmount;
    const subtotal = subtotalBase;

    const surchargeHTML = this.buildSurchargeHTML();

    const originText = r.originFormatted || s1.origin || 'No especificado';
    const destinationText =
        r.destinationFormatted || s1.destination || 'No especificado';

    const html = `
        <div class="tt-modal-summary-content">
            <div class="tt-modal-map-section">
                <h3>Tu Ruta</h3>
                <p><strong>${distanceLabel}</strong> · ${
        r.durationText || 'Duración no disponible'
    }</p>
                <div id="tt-route-map-modal" class="tt-modal-map-container">
                    <div class="tt-map-loading">
                        <div class="tt-map-spinner"></div>
                        <p>Cargando mapa de la ruta...</p>
                    </div>
                </div>
                <div class="tt-modal-route-points">
                    <div><strong>Origen:</strong> ${originText}</div>
                    <div><strong>Destino:</strong> ${destinationText}</div>
                </div>
            </div>

            <div class="tt-modal-details-grid">
                <div class="tt-modal-detail-card">
                    <h4>Cliente</h4>
                    ${
                        s2.customerRut
                            ? `<p><strong>RUT:</strong> ${s2.customerRut}</p>`
                            : ''
                    }
                    <p><strong>Nombre:</strong> ${s2.customerName}</p>
                    <p><strong>Email:</strong> ${s2.customerEmail}</p>
                    <p><strong>Teléfono:</strong> ${this.formatPhone(
                        s2.customerPhone
                    )}</p>
                </div>
                <div class="tt-modal-detail-card">
                    <h4>Viaje</h4>
                    <p><strong>Fecha:</strong> ${this.formatDate(
                        s1.date
                    )}</p>
                    <p><strong>Hora:</strong> ${s1.time}</p>
                    <p><strong>Pasajeros:</strong> ${s1.passengers}</p>
                    <p><strong>Vehículo:</strong> ${
                        p.vehicleName || s2.vehicleTypeText || 'Por definir'
                    }</p>
                    <p><strong>Tipo de traslado:</strong> ${
                        s1.transferTypeText || 'No especificado'
                    }</p>
                    ${
                        r.stops && r.stops.length
                            ? `<p><strong>Paradas intermedias:</strong> ${r.stops.length}</p>`
                            : ''
                    }
                </div>
            </div>

            <div class="tt-modal-pricing-section">
                <h4>Desglose de pagos</h4>

                <div class="tt-pricing-breakdown">

                    ${
                        basePrice
                            ? `
                    <div class="tt-price-row">
                        <span>Tarifa base</span>
                        <span>${this.formatCurrency(basePrice)}</span>
                    </div>`
                            : ''
                    }

                    ${
                        distancePrice
                            ? `
                    <div class="tt-price-row">
                        <span>Distancia (${distanceLabel})</span>
                        <span>${this.formatCurrency(
                            distancePrice
                        )}</span>
                    </div>`
                            : ''
                    }

                    ${
                        passengersPrice
                            ? `
                    <div class="tt-price-row">
                        <span>Pasajeros adicionales (${additionalPassengers})</span>
                        <span>${this.formatCurrency(
                            passengersPrice
                        )}</span>
                    </div>`
                            : ''
                    }

                    ${
                        vehicleFactorAmount
                            ? `
                    <div class="tt-price-row">
                        <span>Factor Vehículo</span>
                        <span>${this.formatCurrency(
                            vehicleFactorAmount
                        )}</span>
                    </div>`
                            : ''
                    }

                    <div class="tt-price-row tt-price-subtotal">
                        <span>Subtotal</span>
                        <span>${this.formatCurrency(subtotal)}</span>
                    </div>

                    ${surchargeHTML}

                    <div class="tt-price-row tt-price-total">
                        <span>Total</span>
                        <span>${this.formatCurrency(total)}</span>
                    </div>
                </div>
            </div>

            <div class="tt-modal-notice">
                <p>Al continuar serás redirigido a Transbank para completar el pago de forma segura.</p>
            </div>
        </div>
    `;

    $('#tt-modal-content').html(html);
    $('.tt-pay-btn').prop('disabled', false).removeClass('tt-disabled');

    this._mapInitialized = false;
    setTimeout(() => {
        this.initSummaryMap();
        this._mapInitialized = true;
    }, 400);
},


buildSurchargeHTML() {
    const s = this.state.pricing.surcharges || {};
    const stopsPrice = this.state.pricing.stopsPrice || 0;
    const stopsCount =
        this.state.pricing.stopsCount || this.state.route.stops.length;

    const cacheKey = JSON.stringify({
        s,
        stopsPrice,
        stopsCount
    });

    if (
        this.cachedSurchargeHtmlKey === cacheKey &&
        this.cachedSurchargeHtml !== null
    ) {
        return this.cachedSurchargeHtml;
    }

    const list = [];

    if (
        s &&
        Array.isArray(s.surcharge_details) &&
        s.surcharge_details.length
    ) {
        const allowedTypes = [
            'weekly',
            'seasonal',
            'specific',
            'time_range',
            'vehicle',
            'stops'
        ];

        s.surcharge_details.forEach((sc) => {
            const name = (sc.name || '').toLowerCase().trim();
            const type = (sc.type || '').toLowerCase().trim();

            if (type === 'vehicle_factor') return;

            if (
                name.includes('pico') ||
                name.includes('punta') ||
                name.includes('hora') ||
                name.includes('peak')
            ) {
                return;
            }

            if (!allowedTypes.includes(type)) return;

            list.push({
                name: sc.name || 'Recargo',
                amount: sc.amount || 0,
                description: sc.description || '',
                type
            });
        });
    }

    if (
        stopsPrice > 0 &&
        stopsCount > 0 &&
        !list.some((x) => x.type === 'stops')
    ) {
        list.push({
            name: `Paradas intermedias (${stopsCount})`,
            amount: stopsPrice,
            description: 'Recargo por paradas adicionales',
            type: 'stops'
        });
    }

    if (!list.length) {
        this.cachedSurchargeHtmlKey = cacheKey;
        this.cachedSurchargeHtml = '';
        return '';
    }

    let total = 0;
    let html = `
        <div class="tt-surcharge-wrapper">
            <div class="tt-surcharge-title">Recargos aplicados</div>
    `;

    list.forEach((sc) => {
        const amt = Number(sc.amount || 0);
        total += amt;

        html += `
            <div class="tt-price-row tt-price-surcharge">
                <span>${sc.name}</span>
                <span>+${this.formatCurrency(amt)}</span>
            </div>
        `;
    });

    if (list.length > 1) {
        html += `
            <div class="tt-price-row tt-price-surcharge-total">
                <span>Total recargos</span>
                <span>+${this.formatCurrency(total)}</span>
            </div>
        `;
    }

    html += `</div>`;

    this.cachedSurchargeHtmlKey = cacheKey;
    this.cachedSurchargeHtml = html;
    return html;
},

initSummaryMap() {
    const el = document.getElementById('tt-route-map-modal');
    if (!el) return;

    const origin = this.state.step1.origin;
    const destination = this.state.step1.destination;

    if (typeof google === 'undefined' || !google.maps) {
        this.renderFallbackMap(el);
        return;
    }

    const map = new google.maps.Map(el, {
        zoom: this.config.map.defaultZoom,
        center: this.config.map.defaultCenter,
        mapTypeControl: false,
        streetViewControl: false
    });

    this._summaryMap = map;

    setTimeout(() => {
        this.forceMapResize(map, el);
    }, 150);

    const ds = new google.maps.DirectionsService();
    const dr = new google.maps.DirectionsRenderer({
        map,
        suppressMarkers: false,
        polylineOptions: this.config.map.polyline
    });

    const waypoints = this.state.route.stops.map((stop) => ({
        location: stop.address,
        stopover: true
    }));

    ds.route(
        {
            origin,
            destination,
            waypoints,
            travelMode: 'DRIVING',
            unitSystem: google.maps.UnitSystem.METRIC
        },
        (res, status) => {
            if (status !== 'OK') {
                this.renderFallbackMap(el);
                return;
            }
            dr.setDirections(res);
        }
    );
},


        renderFallbackMap(el) {
            if (!el) return;
            const origin = this.state.step1.origin;
            const destination = this.state.step1.destination;

            el.innerHTML = `
                <div class="tt-map-fallback">
                    <p><strong>Origen:</strong> ${origin}</p>
                    <p><strong>Destino:</strong> ${destination}</p>
                </div>
            `;
        },

        destroySummaryMap() {
            if (this._summaryMap && typeof google !== 'undefined' && google.maps) {
                google.maps.event.clearInstanceListeners(this._summaryMap);
            }
            this._summaryMap = null;
        },

        forceMapResize(map, el) {
            if (!map || !el) return;
            google.maps.event.trigger(map, "resize");
            const center = map.getCenter();
            if (center) {
                setTimeout(() => map.setCenter(center), 100);
            }
        },

        // =========================================================
        // NAVEGACIÓN ENTRE PASOS
        // =========================================================
        goToStep2() {
            this.readStep1Form();
            const val = this.validateStep1();

            if (!val.isValid) {
                const $err = $('.tt-invalid').first();
                if ($err.length) {
                    $('html, body').animate(
                        { scrollTop: $err.offset().top - 100 },
                        400
                    );
                    $err.focus();
                }
                return;
            }

            if (this.state.route.distanceKm <= 0) {
                const $dest = $('#tt-destination');
                this.setFieldError(
                    $dest,
                    'Debes calcular la ruta antes de continuar'
                );
                if ($dest.length) {
                    $('html, body').animate(
                        { scrollTop: $dest.offset().top - 100 },
                        400
                    );
                }
                return;
            }

            $('#step-1').fadeOut(200, () => {
                $('#step-2').fadeIn(200).addClass('active');
                $('#step-1').removeClass('active');

                $('.tt-step').removeClass('active completed');
                $('.tt-step[data-step="1"]').addClass('completed');
                $('.tt-step[data-step="2"]').addClass('active');

                this.state.step = 2;
                this.syncWindowBooking();

                $('html, body').animate(
                    { scrollTop: $('.tt-booking-form-modern').offset().top - 20 },
                    300
                );
            });
        },

        goBackToStep1() {
            $('#step-2').fadeOut(200, () => {
                $('#step-1').fadeIn(200).addClass('active');
                $('#step-2').removeClass('active');

                $('.tt-step').removeClass('active completed');
                $('.tt-step[data-step="1"]').addClass('active');

                this.state.step = 1;

                $('html, body').animate(
                    { scrollTop: $('.tt-booking-form-modern').offset().top - 20 },
                    300
                );
            });
        },

        goToStep3() {
            this.readStep2Form();
            const v = this.validateStep2();

            if (!v.isValid) {
                const $err = $('.tt-invalid').first();
                if ($err.length) {
                    $('html, body').animate(
                        { scrollTop: $err.offset().top - 100 },
                        400
                    );
                    $err.focus();
                }
                return;
            }

            if (!this.state.pricing.totalPrice || this.state.pricing.totalPrice <= 0) {
                const $veh = $('#tt-vehicle-type');
                this.setFieldError(
                    $veh,
                    'No se ha podido calcular el precio'
                );
                if ($veh.length) {
                    $('html, body').animate(
                        { scrollTop: $veh.offset().top - 100 },
                        400
                    );
                }
                this.calculatePrice();
                return;
            }

            $('#step-2').fadeOut(200, () => {
                $('#step-3').fadeIn(200).addClass('active');
                $('#step-2').removeClass('active');

                $('.tt-step').removeClass('active completed');
                $('.tt-step[data-step="1"]').addClass('completed');
                $('.tt-step[data-step="2"]').addClass('completed');
                $('.tt-step[data-step="3"]').addClass('active');

                this.state.step = 3;
                this.syncWindowBooking();
                this.openSummaryModal();
            });
        },

        // =========================================================
        // PAGO CON TRANSBANK
        // =========================================================
        processPayment() {
            this.syncWindowBooking();
            this.setGlobalError('');

            if (!window.tt_booking_data || !tt_booking_data.totalPrice) {
                this.setGlobalError('No hay datos de reserva válidos');
                return;
            }

            if (typeof tt_ajax === 'undefined') {
                this.setGlobalError('Configuración AJAX no disponible');
                return;
            }

            const $btn = $('.tt-pay-btn');
            const original = $btn.html();

            $btn
                .prop('disabled', true)
                .html('<span class="tt-loading">Conectando con Transbank...</span>');

            $.ajax({
                url: tt_ajax.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'tt_process_payment',
                    booking_data: tt_booking_data,
                    nonce: tt_ajax.nonce
                }
            })
                .done((resp) => {
                    if (
                        !resp.success ||
                        !resp.data ||
                        !resp.data.token ||
                        !resp.data.url
                    ) {
                        this.setGlobalError(
                            resp.data || 'Error iniciando pago con Transbank'
                        );
                        $btn.prop('disabled', false).html(original);
                        return;
                    }

                    const token = resp.data.token;
                    const url = resp.data.url;

                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = url;

                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'token_ws';
                    input.value = token;

                    form.appendChild(input);
                    document.body.appendChild(form);
                    form.submit();
                })
                .fail(() => {
                    this.setGlobalError('Error de conexión con el servidor');
                    $btn.prop('disabled', false).html(original);
                });
        }
    };

    // Exponer en window y arrancar
    window.TTBooking = TTBooking;
    TTBooking.init();
});
