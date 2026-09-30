import { Controller } from '@hotwired/stimulus';

/*
 * TYPED, NOT CLICKED (ruled 28 Sep, #48). The delete button stays dead until
 * the field holds the record's reference exactly; the server asks the same
 * question again, so this only spares a Super Admin a refused post.
 */
export default class extends Controller {
    static targets = ['typed', 'go'];
    static values = { reference: String };

    connect() {
        this.check();
    }

    check() {
        this.goTarget.disabled = this.typedTarget.value !== this.referenceValue;
    }
}
