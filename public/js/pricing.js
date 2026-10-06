/* Caffenet Lite — pricing.js
 * Client twin of App\Services\PricingService — identical rules so the live
 * preview matches the server's authoritative computation. */
(function () {
  'use strict';

  const DEFAULT_MULTIPLIERS = { normal: 1, urgent: 1.3, immediate: 1.6 };
  const DEFAULT_COURIER_COST = 35000;

  function findOptionExtraPrice(service, fieldId, value) {
    const field = (service.fields || []).find((f) => f.id === fieldId);
    if (!field || !field.options) return 0;
    const opt = field.options.find((o) => o.value === value);
    return Number((opt && opt.extraPrice) || 0);
  }

  window.Pricing = {
    DEFAULT_MULTIPLIERS,
    DEFAULT_COURIER_COST,

    calculate(input) {
      const service = input.service;
      const formData = input.formData || {};
      const multipliers = input.multipliers || DEFAULT_MULTIPLIERS;
      const courierCost = input.courierCost != null ? input.courierCost : DEFAULT_COURIER_COST;
      let subtotal = Number(service.basePrice || 0);

      if (service.id === 'srv-print-doc') {
        const pages = Math.max(1, Number(formData.pageCount || 1));
        const copies = Math.max(1, Number(formData.copies || 1));
        const isColor = formData.printColor === 'color';
        const isA3 = formData.paperSize === 'A3';
        const binding = formData.binding;

        const pagePrice = isColor ? 5500 : 1500;
        const sizeMultiplier = isA3 ? 2 : 1;

        let bindingCost = 0;
        if (binding && binding !== 'none') {
          bindingCost = findOptionExtraPrice(service, 'binding', binding);
          if (!bindingCost) {
            if (binding === 'plastic_coil') bindingCost = 25000;
            if (binding === 'metal_coil') bindingCost = 40000;
          }
        }

        subtotal = pages * pagePrice * sizeMultiplier * copies + bindingCost;
      } else {
        (service.fields || []).forEach((field) => {
          if (field.type === 'select' && field.options) {
            const selected = field.options.find((o) => o.value === formData[field.id]);
            if (selected && selected.extraPrice) subtotal += Number(selected.extraPrice);
          }
        });
      }

      const urgencyMultiplier = multipliers[input.urgency] != null ? multipliers[input.urgency] : 1;
      const deliveryCost = input.deliveryMethod === 'courier' ? Number(courierCost) : 0;
      const totalBeforeDiscount = Math.round(subtotal * urgencyMultiplier) + deliveryCost;
      const discountAmount = Number(input.discountAmount || 0);
      const payable = Math.max(0, totalBeforeDiscount - discountAmount);

      return { subtotal, urgencyMultiplier, deliveryCost, totalBeforeDiscount, discountAmount, payable };
    },
  };
})();
