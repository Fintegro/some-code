package com.fintegro.smarthomeworks.view.fragments;

/**
 * Created by oleksandr on 24.08.15.
 * For more info contact us <a href="mailto:fintegro@gmail.com">Fintegro Inc.</a>
 */

import android.animation.Animator;
import android.animation.AnimatorListenerAdapter;
import android.annotation.TargetApi;
import android.content.Context;
import android.content.SharedPreferences;
import android.os.Build;
import android.os.Bundle;
import android.preference.PreferenceManager;
import android.text.TextUtils;
import android.util.Log;
import android.view.KeyEvent;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.inputmethod.EditorInfo;
import android.view.inputmethod.InputMethodManager;
import android.widget.EditText;
import android.widget.TextView;

import com.fintegro.smarthomeworks.BuildConfig;
import com.fintegro.smarthomeworks.R;
import com.fintegro.smarthomeworks.system.Constants;
import com.fintegro.smarthomeworks.system.JSONError;
import com.fintegro.smarthomeworks.system.SmartApplication;
import com.fintegro.smarthomeworks.system.database.DataBaseHelper;
import com.fintegro.smarthomeworks.system.database.dao.AgentDAO;
import com.fintegro.smarthomeworks.system.rest.beans.tordev.Agent;
import com.fintegro.smarthomeworks.system.rest.beans.tordev.ResponseHeaders;
import com.fintegro.smarthomeworks.system.rest.requests.LoginRequestTordev;
import com.octo.android.robospice.SpiceManager;
import com.octo.android.robospice.persistence.DurationInMillis;
import com.octo.android.robospice.persistence.exception.SpiceException;
import com.octo.android.robospice.request.listener.RequestListener;


public class LoginFragment extends EcoFragment implements View.OnClickListener, RequestListener<Agent> {
    private EditText emailEdit;
    private EditText passwordEdit;
    private View mProgressView;
    private View mLoginFormView;
    private LoginRequestTordev loginRequest;
    private SharedPreferences settings;
    private String server;

    public LoginFragment() {
        // Required empty public constructor
    }

    public static LoginFragment newInstance() {
        return new LoginFragment();
    }

    @Override
    public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        SharedPreferences preferences = PreferenceManager.getDefaultSharedPreferences(getActivity());
        server = preferences.getString(Constants.REST_SERVER, getString(R.string.rest_default));
        settings = getActivity().getSharedPreferences(Constants.PREFS_NAME, 0);
        tryLogin();
    }

    @Override
    public View onCreateView(LayoutInflater inflater, ViewGroup container, Bundle savedInstanceState) {
        View rootView = null;
        if (!isLoginSessionValid()) {
            // Inflate the layout for this fragment
            rootView = inflater.inflate(R.layout.fragment_login, container, false);
            // Set up the login form.
            emailEdit = (EditText) rootView.findViewById(R.id.emailEdit);
            passwordEdit = (EditText) rootView.findViewById(R.id.passwordEdit);
            passwordEdit.setOnEditorActionListener(new TextView.OnEditorActionListener() {
                @Override
                public boolean onEditorAction(TextView textView, int id, KeyEvent keyEvent) {
                    if (id == R.id.emailEdit || id == EditorInfo.IME_NULL) {
                        attemptLogin();
                        return true;
                    }
                    return false;
                }
            });

            rootView.findViewById(R.id.loginButton).setOnClickListener(this);
            rootView.findViewById(R.id.settingsTextView).setOnClickListener(this);
            mLoginFormView = rootView.findViewById(R.id.login_form);
            mProgressView = rootView.findViewById(R.id.login_progress);
            ((TextView) rootView.findViewById(R.id.appVersion)).setText(BuildConfig.VERSION_NAME);
        }
        return rootView;
    }

    @Override
    public void onClick(View v) {
        switch (v.getId()) {
            case R.id.loginButton:
                setKeyboardVisibility();
                attemptLogin();
                break;
            case R.id.settingsTextView:
                getFragmentManager().beginTransaction().replace(R.id.item_detail_container, SettingsFragment.newInstance(), Constants.ACTIVE_FRAGMENT).commit();
                break;
        }
    }

    private void performRequest(String email, String password) {
        SpiceManager spiceManager = getSpiceManager();
        loginRequest = new LoginRequestTordev(server, email, password);
        String lastRequestCacheKey = loginRequest.createCacheKey();
        spiceManager.execute(loginRequest, lastRequestCacheKey, DurationInMillis.ONE_MINUTE, this);
    }

    @Override
    public void onRequestFailure(SpiceException spiceException) {
        String error = JSONError.parseError(spiceException, true);
        Log.e(Constants.TAG, error);
        createDialog(error);
        showProgress(false);
    }

    @Override
    public void onRequestSuccess(Agent user) {
        SmartApplication smartApplication = (SmartApplication) getActivity().getApplication();
        ResponseHeaders loginHeaders = loginRequest.getHeaders();

        //some times get empty header. We show error in that cases
        if (loginHeaders == null) {
            createDialog(getString(R.string.error_api_error));
            showProgress(false);
            return;
        }

        //save Agent ID for TermsFragment
        SharedPreferences.Editor editor = settings.edit();

        try {
            AgentDAO agentDAO = new AgentDAO(getActivity());
            agentDAO.addAgent(user);
            editor.putInt(DataBaseHelper.KEY_AGENT_ID, user.getIndependent_contractor().getIc_code()).apply();
        } catch (Exception e) {
            String message = e.getMessage();
            if (message != null) {
                Log.e(Constants.TAG, message);
            }
        }

        //save headers
        editor.putString(Constants.ACCESS_TOKEN, loginHeaders.getAccessToken());
        editor.putString(Constants.TOKEN_TYPE, loginHeaders.getTokenType());
        editor.putString(Constants.CLIENT, loginHeaders.getClient());
        editor.putString(Constants.EXPIRY, loginHeaders.getExpiry());
        editor.putString(Constants.UID, loginHeaders.getUid());
        // Commit the edits!
        editor.apply();

        smartApplication.setResponseHeaders(loginHeaders);
        saveLogin();
        showProgress(false);
        getFragmentManager().beginTransaction().replace(R.id.item_detail_container, HomeFragment.newInstance()).commit();
    }

    /**
     * Attempts to sign in or register the account specified by the login form.
     * If there are form errors (invalid email, missing fields, etc.), the
     * errors are presented and no actual login attempt is made.
     */
    public void attemptLogin() {
        // Reset errors.
        emailEdit.setError(null);
        passwordEdit.setError(null);

        // Store values at the time of the login attempt.
        String email = emailEdit.getText().toString();
        String password = passwordEdit.getText().toString();

        boolean cancel = false;
        View focusView = null;

        // Check for a valid password, if the user entered one.
        if (!TextUtils.isEmpty(password) && !isPasswordValid(password)) {
            passwordEdit.setError(getString(R.string.error_invalid_password));
            focusView = passwordEdit;
            cancel = true;
        }

        // Check for a valid email address.
        if (TextUtils.isEmpty(email)) {
            emailEdit.setError(getString(R.string.error_field_required));
            focusView = emailEdit;
            cancel = true;
        } else if (!isEmailValid(email)) {
            emailEdit.setError(getString(R.string.error_invalid_email));
            focusView = emailEdit;
            cancel = true;
        }

        if (cancel) {
            // There was an error; don't attempt login and focus the first
            // form field with an error.
            focusView.requestFocus();
        } else {
            // Show a progress spinner, and kick off a background task to
            // perform the user login attempt.
            showProgress(true);
            performRequest(email, password);
        }
    }

    private boolean isPasswordValid(String password) {
        return password.length() > 4;
    }

    /**
     * Shows the progress UI and hides the login form.
     */
    @TargetApi(Build.VERSION_CODES.HONEYCOMB_MR2)
    public void showProgress(final boolean show) {
        // On Honeycomb MR2 we have the ViewPropertyAnimator APIs, which allow
        // for very easy animations. If available, use these APIs to fade-in
        // the progress spinner.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.HONEYCOMB_MR2) {
            int shortAnimTime = getResources().getInteger(android.R.integer.config_shortAnimTime);

            mLoginFormView.setVisibility(show ? View.GONE : View.VISIBLE);
            mLoginFormView.animate().setDuration(shortAnimTime).alpha(
                    show ? 0 : 1).setListener(new AnimatorListenerAdapter() {
                @Override
                public void onAnimationEnd(Animator animation) {
                    mLoginFormView.setVisibility(show ? View.GONE : View.VISIBLE);
                }
            });

            mProgressView.setVisibility(show ? View.VISIBLE : View.GONE);
            mProgressView.animate().setDuration(shortAnimTime).alpha(
                    show ? 1 : 0).setListener(new AnimatorListenerAdapter() {
                @Override
                public void onAnimationEnd(Animator animation) {
                    mProgressView.setVisibility(show ? View.VISIBLE : View.GONE);
                }
            });
        } else {
            // The ViewPropertyAnimator APIs are not available, so simply show
            // and hide the relevant UI components.
            mProgressView.setVisibility(show ? View.VISIBLE : View.GONE);
            mLoginFormView.setVisibility(show ? View.GONE : View.VISIBLE);
        }
    }

    public void setKeyboardVisibility() {
        InputMethodManager imm = (InputMethodManager) getActivity().getSystemService(Context.INPUT_METHOD_SERVICE);
        View currentFocus = getActivity().getCurrentFocus();
        if (currentFocus != null) {
            imm.hideSoftInputFromWindow(currentFocus.getWindowToken(), 0);
        }
    }

    @Override
    public String getFragmentName() {
        return null;
    }

    @Override
    public void saveDataInDB() {
    }

    @Override
    public boolean canChangeScreen() {
        return true;
    }

    /**
     * Save login/password and last time login in
     */
    public void saveLogin() {
        SharedPreferences.Editor editor = settings.edit();
        long seconds = System.currentTimeMillis();
        editor.putLong(Constants.LAST_LOGIN, seconds);
        editor.putString(Constants.LOGIN_NAME, emailEdit.getText().toString());
        editor.putString(Constants.PASSWORD, passwordEdit.getText().toString());

        // Commit the edits!
        editor.apply();
    }

    /**
     * Method checks if we can login with current session. Then we use saved params
     */
    private void tryLogin() {
        if (isLoginSessionValid()) {
            String accessToken = settings.getString(Constants.ACCESS_TOKEN, null);
            String tokenType = settings.getString(Constants.TOKEN_TYPE, null);
            String client = settings.getString(Constants.CLIENT, null);
            String expiry = settings.getString(Constants.EXPIRY, null);
            String uid = settings.getString(Constants.UID, null);
            ResponseHeaders loginHeaders = new ResponseHeaders(accessToken, tokenType, client, expiry, uid);
            SmartApplication smartApplication = (SmartApplication) getActivity().getApplication();
            smartApplication.setResponseHeaders(loginHeaders);
            getFragmentManager().beginTransaction().replace(R.id.item_detail_container, HomeFragment.newInstance()).commit();
        }
        settings.edit().putBoolean(Constants.HIDDEN, false).apply();
    }
}
